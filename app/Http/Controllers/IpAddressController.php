<?php

namespace IXP\Http\Controllers;

/*
 * Copyright (C) 2009-2017 Internet Neutral Exchange Association Company Limited By Guarantee.
 * All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

use D2EM, Redirect;

use IPTools\{
    IP,
    Network
};

use Entities\{
    IPv4Address            as IPv4AddressEntity,
    IPv6Address            as IPv6AddressEntity,
    Vlan                   as VlanEntity
};

use IXP\Utils\View\Alert\Alert;
use IXP\Utils\View\Alert\Container as AlertContainer;

use Illuminate\View\View;

use Illuminate\Http\{
    RedirectResponse,
    JsonResponse,
    Request
};

use IXP\Http\Requests\{
    DeleteIpAddressesByNetwork,
    StoreIpAddress
};


/**
 * IP Address Controller
 *
 * @author     Yann Robin <yann@islandbridgenetworks.ie>
 * @author     Barry O'Donovan <barry@islandbridgenetworks.ie>
 *
 * @category   Admin
 * @copyright  Copyright (C) 2009-2017 Internet Neutral Exchange Association Company Limited By Guarantee
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class IpAddressController extends Controller
{

    /**
     * Return the entity depending on the protocol
     *
     * @param int       $protocol   Protocol of the IP address
     * @param boolean   $entity     Do we need to return the entity ?
     *
     * @return IPv4AddressEntity | IPv6AddressEntity | integer
     */
    private function processProtocol( int $protocol , bool $entity = true )
    {
        if( !in_array( $protocol, [ 4,6 ] ) ){
            abort( 404 , 'Unknown protocol');
        }

        if( $entity ){
            return $protocol == 4 ? IPv4AddressEntity::class : IPv6AddressEntity::class;
        } else {
            return $protocol;
        }
    }

    /**
     * Display the list of the IP Address (IPv4 or IPv6)
     *
     * @param int   $protocol   Protocol of the IP address
     * @param int   $vid        ID of the vlan
     *
     * @return view
     */
    public function list( int $protocol, int $vid = null ): View
    {
        $vlan = null;
        if( $vid ) {
            if( !( $vlan = D2EM::getRepository( VlanEntity::class )->find( $vid ) ) ) {
                abort( 404 , 'Unknown vlan');
            }
        }

        $ips = ( $vlan ) ? D2EM::getRepository( $this->processProtocol( $protocol, true ) )->getAllForList( $vlan->getId() ) : [] ;

        return view( 'ip-address/list' )->with([
            'ips'                       => $ips,
            'vlans'                     => D2EM::getRepository( VlanEntity::class )->getNames(),
            'protocol'                  => $protocol,
            'vlan'                      => $vlan ?? false

        ]);
    }

    /**
     * Display the form to add an IP Address (IPv4 or IPv6)
     *
     * @param int   $protocol   Protocol of the IP address
     *
     * @return view
     */
    public function add( int $protocol ): View
    {
        return view( 'ip-address/add' )->with([
            'vlans'                     => D2EM::getRepository( VlanEntity::class )->getNames(),
            'protocol'                  => $this->processProtocol( $protocol, false )
        ]);
    }


    /**
     * Edit the core links associated to a core bundle
     *
     * @param   StoreIpAddress      $request instance of the current HTTP request
     * @return  RedirectResponse
     */
    public function store( StoreIpAddress $request ): RedirectResponse {

        /** @var VlanEntity $vlan */
        $vlan     = D2EM::getRepository( VlanEntity::class )->find( $request->input('vlan' ) );
        $network  = Network::parse( trim( htmlspecialchars( $request->input('network' ) )  ) );
        $skip     = (bool)$request->input( 'skip',     false );
        $decimal  = (bool)$request->input( 'decimal',  false );
        $overflow = (bool)$request->input( 'overflow', false );

        if( $network->getFirstIP()->version == 'IPv6' ) {
            $result = D2EM::getRepository( IPv6AddressEntity::class )->bulkAdd(
                D2EM::getRepository( IPv6AddressEntity::class )->generateSequentialAddresses( $network, $decimal, $overflow ),
                $vlan, $skip
            );
        } else {
            $result = D2EM::getRepository( IPv4AddressEntity::class )->bulkAdd(
                D2EM::getRepository( IPv4AddressEntity::class )->generateSequentialAddresses( $network ),
                $vlan, $skip
            );
        }

        if( !$skip && count( $result['preexisting'] ) ) {
            AlertContainer::push( "No addresses were added as the following addresses already exist in the database: "
                . implode( ', ', $result['preexisting'] ) . ". You can check <em>skip</em> below to add only the addresses "
                . "that do not already exist.", Alert::DANGER );
            return Redirect::back()->withInput();
        }

        if( count( $result['new'] ) == 0 ) {
            AlertContainer::push( "No addresses were added. " . count( $result['preexisting'] ) . " already exist in the database.",
                Alert::WARNING
            );
            return Redirect::back()->withInput();
        }

        AlertContainer::push( count( $result['new'] ) . ' new IP addresses added to <em>' . $vlan->getName() . '</em>. '
            . ( $skip ? 'There were ' . count( $result['preexisting'] ) . ' preexisting address(es).' : '' ),
            Alert::SUCCESS
        );

        return Redirect::to( route( 'ip-address@list', [ 'protocol' => $network->getFirstIP()->getVersion() == 'IPv6' ? '6' : '4', 'vlan' => $vlan->getId() ] ) );
    }


    /**
     * Display the form to delete free IP addresses in a VLAN
     *
     * @param  DeleteIpAddressesByNetwork  $request            Instance of the current HTTP request
     * @param  int      $vlanid                Id of the VLan
     *
     * @return View | Redirect
     */
    public function deleteByNetwork( DeleteIpAddressesByNetwork $request, int $vlanid ) {

        /** @var VlanEntity $v */
        if( !( $v = D2EM::getRepository( VlanEntity::class )->find( $vlanid ) ) ) {
            abort(404);
        }

        if( $request->input( 'network' ) ) {

            $network  = Network::parse( trim( htmlspecialchars( $request->input('network' ) )  ) );

            if( $network->getFirstIP()->version == 'IPv6' ) {
                $ips = D2EM::getRepository( IPv6AddressEntity::class )->getFreeAddressesFromList( $v,
                    D2EM::getRepository( IPv6AddressEntity::class )->generateSequentialAddresses( $network, false, false )
                );
            } else {
                $ips = D2EM::getRepository( IPv4AddressEntity::class )->getFreeAddressesFromList( $v,
                    D2EM::getRepository( IPv4AddressEntity::class )->generateSequentialAddresses( $network )
                );
            }

        } else {
            $ips = [];
        }

        //dd($ips);

        return view( 'ip-address/delete-by-network' )->with([
            'vlan'                      => $v,
            'network'                   => $request->input( 'network', '' ),
            'ips'                       => $ips,
        ]);
    }

    // DeleteIpAddressesByNetwork

    public function doDeleteByNetwork( DeleteIpAddressesByNetwork $r, int $vlanid ) {
        dd($r);
    }

    /**
     * Get all the free IP address for a Vlan or all the free IP address for a network (e.g. 192.0.2.48/29) inside a Vlan
     *
     * @param  VlanEntity   $vlan            Vlan object
     * @param  bool         $networkSearch   Are we searching for a network
     * @param  string       $network         The network that we are searching
     *
     * @return array | boolean
     */
    public function getFreeIpAddresses( $vlan, $network  ){
        $ips = [];

        if( !$networkSearch  ){
            foreach( $vlan->getIPv4Addresses() as $ipv4 ){
                if( !$ipv4->getVlanInterface() ){
                    $ips[ 'ipv4' ][] = $ipv4;
                }
            }

            foreach( $vlan->getIPv6Addresses() as $ipv6 ){
                if( !$ipv6->getVlanInterface() ){
                    $ips[ 'ipv6' ][] = $ipv6;
                }
            }
        } else {
            if( $network ){
                $network = explode( '/', $network );

                // $network[ 0 ] => IP address, if exist $network[ 1 ] => subnet
                if( !filter_var( $network[ 0 ], FILTER_VALIDATE_IP ) ){
                    AlertContainer::push( 'The IP address format is invalid', Alert::DANGER );
                    return false;
                }

                if( !isset( $network[ 1 ] ) ){
                    AlertContainer::push( 'The IP Address must have a subnet', Alert::DANGER );
                    return false;
                }

                $ip = new IP( $network[ 0 ] );

                $protocol = $ip->getVersion() == 'IPv4' ? 4 : 6;

                $entity = $this->processProtocol( $protocol, true );
                $networks = Network::parse( $network[ 0 ]. '/'.$network[ 1 ] );

                foreach( $networks as $ipParse ) {
                    if( $ipAddress = D2EM::getRepository( $entity )->findOneBy( [ "Vlan" => $vlan->getId() , 'address' => (string)$ipParse ] ) ){
                        if( !$ipAddress->getVlanInterface() ){
                            $ips[ 'ip' ][] = $ipAddress;
                        }
                    }
                }

            }

        }

        return $ips;
    }


    /**
     * Delete an IP address
     *
     * @param   int     $protocol   Protocol of the IP address
     * @param   int     $id         Router that need to be deleted
     *
     * @return JsonResponse
     */
    public function delete( int $protocol, int $id ): JsonResponse {

        if( !( $ip = D2EM::getRepository( $this->processProtocol( $protocol, true ) )->find( $id ) ) ) {
            abort(404);
        }

        if( $ip->getVlanInterface() ){
            AlertContainer::push( 'This IP address is assigned to a VLAN interface.', Alert::DANGER );
            return response()->json( [ 'success' => false ] );
        }

        D2EM::remove($ip);
        D2EM::flush();

        AlertContainer::push( 'The IP has been successfully deleted.', Alert::SUCCESS );
        return response()->json( [ 'success' => true ] );
    }


    /**
     * Delete IP addresses for a Vlan
     *
     * @param  Request  $request            Instance of the current HTTP request
     *
     * @return JsonResponse
     */
    public function deleteForVlan( Request $request ) : JsonResponse {
        /** @var VlanEntity $v */
        if( !( $v = D2EM::getRepository( VlanEntity::class )->find( $request->input( 'vid' ) ) ) ) {
            abort(404);
        }

        $ips = $this->getFreeIpAddress( $v, $request->input( 'network' ) ? true : false, $request->input( 'network' ) );

        foreach( $ips as $protocol => $ip){
            foreach( $ip as $address ){
                D2EM::remove( $address );
            }
        }

        D2EM::flush();

        AlertContainer::push( 'The IP Addresses of the Vlan ' .$v->getName(). ' have been successfully deleted.', Alert::SUCCESS );
        return response()->json( [ 'success' => true ] );
    }


}