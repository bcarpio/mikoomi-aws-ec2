#!/usr/bin/php
<?php

/**********************************************************************
                       Mikoomi GPL Licene
***********************************************************************
   This program is free software: you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.

   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License
   along with this program.  If not, see <http://www.gnu.org/licenses/>.
***********************************************************************/

// Options to this script are to be passed from the command line
// as shown in the usage below.

$options = getopt("da:k:s:z:e:") ;
$command_name = basename($argv[0]) ;
$command_version = "1.3" ;

if (empty($options) or 
    empty($options['a']) or
    empty($options['k']) or
    empty($options['s']) or
    empty($options['z']) 
   )
{
     echo "
$command_name Version $command_version
Usage : $command_name [-d] -a <AWS_Account> -k <AWS_Access_Key> -s <AWS_Secret_Access_Key>  [-e <AWS_HTTPS_Endpoint>] -z <Zabbix_Name>
where
   -d = Run in debug mode
   -a = Your AWS account
   -k = Access key for your AWS account
   -s = Secret key to your AWS account's access key
   -e = HTTPS endpoint for AWS query (default already embedded in this agent)
   -z = Name (hostname) in the Zabbix UI

"  ;

exit ;
}

$creds = array(
                'zabbix_name' => $options['z'] ,
                'account_id' =>  $options['a'] ,
                'access_key' =>  $options['k'] ,
                'secret_key' =>  $options['s'] ,
                'aws_endpoint' =>  empty($options['e']) ? 'ec2.us-east-1.amazonaws.com' : $options['e'] 
              ) ;

$debug_mode = isset($options['d']) ;
$zabbix_name = $creds['zabbix_name'] ;

$log_file_name = "/tmp/${command_name}_${zabbix_name}.log" ;
$log_file_handle = fopen($log_file_name, 'w') ;
$log_file_data = array() ;

$data_file_name = "/tmp/${command_name}_${zabbix_name}.data" ;
$data_file_handle = fopen($data_file_name, 'w') ;

if (!($data_file_handle))
{
      write_to_log_file("$command_name:There was an error in opening data file $data_file_name\n") ;
      exit ;
}

$md5_checksum_string = md5_file($argv[0]) ;

if ($debug_mode)
{
   write_to_log_file("$command_name Version $command_version\n") ;
}


//-------------------------------------------------------------------------//
function write_to_log_file($output_line)
//-------------------------------------------------------------------------//
{
   global $command_name ;
   global $log_file_handle ;
   fwrite($log_file_handle, "$output_line\n") ;
}
//-------------------------------------------------------------------------//



//-------------------------------------------------------------------------//
function debug_output($output_line)
//-------------------------------------------------------------------------//
{
   global $debug_mode ;
   global $command_name ;
   global $log_file_handle ;
   if ($debug_mode) {
      fwrite($log_file_handle, "$output_line\n") ;
   }
}
//-------------------------------------------------------------------------//


//-------------------------------------------------------------------------//
function write_to_data_file($output_line)
//-------------------------------------------------------------------------//
{
   global $data_file_handle ;
   fwrite($data_file_handle, "$output_line\n") ;
}
//-------------------------------------------------------------------------//


//-------------------------------------------------------------------------//
function exec_ec2_query($creds, $ec2_api_action)
//-------------------------------------------------------------------------//
{
   $query_time = time() ;
   
   // Define query string keys/values
   $http_query_params = array(
       'Action' => $ec2_api_action,
       'AWSAccessKeyId' => $creds['access_key'],
       'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
       'Version' => '2012-06-01',
       'SignatureVersion' => 2,
       'SignatureMethod' => 'HmacSHA256'
   );

   // Note that for "DescribeImages" we are filtering for AMI images for which the "self account"
   // has implicit (i.e. is the owner of the image) or explicit permission.
   if ($ec2_api_action == "DescribeImages")
   {
      //$http_query_params['Owner.1'] = "self" ;
      //$http_query_params['Owner.2'] = "explicit" ;
      $http_query_params['Owner'] = "self" ;
   }
 

   // Note that for "DescribeSpotPriceHistory" we need to provide the start time and end time
   // for the spot price. If we do not (the times are optional), then we get a long, long, long
   // dataset for the spot price - i.e. the spotprice history will begin from whatever is 
   // the oldest cached/stored in the Amazon API system.
   // Consequently, we request the price within the last 1 hour.
   if ($ec2_api_action == "DescribeSpotPriceHistory")
   {
      $http_query_params['StartTime'] = gmdate('Y-m-d\TH:i:s\Z', mktime() - 30) ;
      $http_query_params['EndTime'] = gmdate('Y-m-d\TH:i:s\Z', mktime()) ;
   }

   // EC2 API requires the query parameters to be 
   // sorted in "natural order" and then URL encoded
   uksort($http_query_params, 'strnatcmp');
   $query_string = '';
   foreach ($http_query_params as $key => $val) {
       $query_string .= "&{$key}=".rawurlencode($val);
   }
   
   $query_string = substr($query_string, 1);

   // Formulate the HTTP query string
   $http_get_string = "GET\n"
        . "$creds[aws_endpoint]\n"
        . "/\n"
        . $query_string;
   
   // Generate base64-encoded RFC 2104-compliant HMAC-SHA256
   // signature with Secret Key using PHP 5's native 
   // hash_hmac function.
   $http_query_params['Signature'] = base64_encode(
       hash_hmac('sha256', $http_get_string, $creds['secret_key'], true)
   );
   
   // simple GET request to EC2 Query API with regular URL 
   // encoded query string
   $http_request = "https://$creds[aws_endpoint]/?" . http_build_query( $http_query_params);

   debug_output(__FILE__ . ':' . 
                __FUNCTION__ . ':' . 
                __LINE__ . ':' . 
                "HTTP request sent to EC2 for $ec2_api_action = $http_request") ;
   

   $http_request_result = file_get_contents($http_request);
   
   // Check for errors in HTTPS  GET operation
   if (is_null($http_request_result) or empty($http_request_result)) {
      write_to_log_file( "Error: HTTP result from EC2 was either null/empty or errors encountered\n") ;
      write_to_log_file( "HTTP Result : $http_request_result\n") ;
      exit ;
   }
   

   // The result from EC2 EC2 is in XML format - so convert it to an XML object.
   $xml_object = simplexml_load_string($http_request_result) ;
   
   // Since every successful query to the EC2 API will have a 
   // "RequestId" returned, check for that first.
   $requestId = $xml_object->requestId ;
   if (is_null($requestId) or empty($requestId)) {
      write_to_log_file(STDERR, "Error: EC2 requestId was either null or empty\n") ;
      exit ;
   }
   
   return $xml_object ;
   
   }
//-------------------------------------------------------------------------//


//-------------------------------------------------------------------------//
// EC2_DescribeImages 
//-------------------------------------------------------------------------//
function EC2_DescribeImages($creds)
{

   $zabbix_name = $creds['zabbix_name'] ;
   
   $xml_object =  exec_ec2_query($creds, 'DescribeImages') ;
   
   debug_output(__FILE__ . ':' . 
                __FUNCTION__ . ':' . 
                __LINE__ . ':' . 
                "HTTP result received from EC2 for DescribeImages = " . var_export($xml_object, true) ) ;
   
   // Initialize counters
   $total_image_count = 0 ;
   $public_image_count = 0 ;
   
   // A successful query to the EC2 API will have one or more
   // imageSet - so iterate through that.
   foreach ($xml_object->imagesSet as $imagesSet) {
      if (empty($imagesSet)) {
          break ;
      }
      foreach ($imagesSet->item as $item) {
               $total_image_count++ ;
               if ($item->isPublic == true) {
                   $public_image_count++ ;
               }
      }
   }
   
   // Print all the data to be sent to Zabbix
   write_to_data_file("$zabbix_name Images_Total $total_image_count") ;
   write_to_data_file("$zabbix_name Images_Public $public_image_count") ;
   $temp_total = $total_image_count - $public_image_count ;
   write_to_data_file("$zabbix_name Images_Non-Public $temp_total") ; 
}
//-------------------------------------------------------------------------//
   
   
//-------------------------------------------------------------------------//
// EC2_DescribeInstances 
//-------------------------------------------------------------------------//
function EC2_DescribeInstances($creds) 
{

   $zabbix_name = $creds['zabbix_name'] ;

   $xml_object =  exec_ec2_query($creds, 'DescribeInstances') ;
	
   
   debug_output(__FILE__ . ':' . 
                __FUNCTION__ . ':' . 
                __LINE__ . ':' . 
                "HTTP result received from AWS for DescribeInstances = " . var_export($xml_object, true) ) ;
   
   // Initialize counters
   $instance_count = 0 ;
   $spot_instance_count = 0 ;
   
   $instance_state_array = array( 'pending' => 0,
                                  'running' => 0,
                                  'shutting-down' => 0,
                                  'terminated' => 0,
                                  'stopping' => 0,
                                  'stopped' => 0
                                ) ;
   
   $instance_type_array = array('c1.medium'=> 0,
                                'c1.xlarge'=> 0,
                                'cc1.4xlarge'=> 0,
                                'cg1.4xlarge'=> 0,
                                'm1.large'=> 0,
                                'm1.medium'=> 0,
                                'm1.small'=> 0,
                                'm1.xlarge'=> 0,
                                'm2.2xlarge'=> 0,
                                'm2.4xlarge'=> 0,
                                'm2.xlarge'=> 0,
                                't1.micro'=> 0
                                ) ;
   
   $monitoring_status_array = array( 'enabled'=> 0,
                                     'pending'=> 0,
                                     'disabling'=> 0,
                                     'disabled' => 0
                                    ) ;
   
   $instance_state_reason_array = array( 'Server.SpotInstanceTermination' => 0,
                                         'Server.InternalError' => 0,
                                         'Server.InsufficientInstanceCapacity' => 0,
                                         'Client.InternalError' => 0,
                                         'Client.InstanceInitiatedShutdown' => 0,
                                         'Client.UserInitiatedShutdown' => 0,
                                         'Client.VolumeLimitExceeded' => 0,
                                         'Client.InvalidSnapshot.NotFound' => 0,
                                       ) ;
   
   // A successful query to the AWS API will have one or more
   // reservationSet - so iterate through that.
   // Each reservationSet in turn will have zero, one or more
   // instanceSets  and each instanceSet will have zero or more
   // Amazon EC2 instances.
   foreach ($xml_object->reservationSet->item as $reservationSet) {
      if (empty($reservationSet)) {
          break ;
      }
      foreach ($reservationSet->instancesSet->item as $instance) {
   
                   $instance_count++ ;
   
                   $spot_instance_indicator = $instance->spotInstanceRequestId ;
                   if (!empty($spot_instance_indicator)) {
                      $spot_instance_count++ ;
                   }
   
                   $instance_state = $instance->instanceState->name ;
                   if (!empty($instance_state)) {
                      $instance_state_array["$instance_state"]++ ;
                   }
   
                   $instance_type = $instance->instanceType ;
                   if (!empty($instance_type)) {
                      $instance_type_array["$instance_type"]++ ;
                   }
   
                   $instance_monitoring_status = $instance->monitoring->state ;
                   if (!empty($instance_monitoring_status)) {
                      $monitoring_status_array["$instance_monitoring_status"]++ ;
                   }
   
                   $instance_state_reason = $instance->stateReason->message ;
                   if (!empty($instance_state_reason)) {
                      $instance_state_reason_array["$instance_state_reason"]++ ;
                   }
      }
   }
   
   // Print all the data to be sent to Zabbix
   write_to_data_file("$zabbix_name Instances_Total $instance_count") ;
   
   write_to_data_file("$zabbix_name Spot_Instances_Total $spot_instance_count") ;
   
   $temp_counter = 0 ;
   foreach ($instance_state_array as $instance_state_key => $instance_state_value) {
           write_to_data_file("$zabbix_name Instances_State_${instance_state_key} $instance_state_value") ;
           $temp_counter += $instance_state_value ;
   }
   if ($instance_count <> $temp_counter) {
           write_to_data_file("$zabbix_name Instances_State_Unknown " . abs($instance_count - $temp_counter)) ;
   }
   
   $temp_counter = 0 ;
   foreach ($instance_type_array as $instance_type_key => $instance_type_value) {
           write_to_data_file("$zabbix_name Instances_Type_${instance_type_key} $instance_type_value") ;
           $temp_counter += $instance_type_value ;
   }
   if ($instance_count <> $temp_counter) {
           write_to_data_file("$zabbix_name Instances_Type_Unknown " . abs($instance_count - $temp_counter)) ;
   }
   
   $temp_counter = 0 ;
   foreach ($monitoring_status_array as $monitoring_status_key => $monitoring_status_value) {
           write_to_data_file("$zabbix_name Instances_Monitoring_${monitoring_status_key} $monitoring_status_value") ;
   }

   if ($instance_count <> $temp_counter) {
           write_to_data_file("$zabbix_name Instances_Without_Monitoring " . abs($instance_count - $temp_counter)) ;
   }
   
   foreach ($instance_state_reason_array as $instance_state_reason_key => $instance_state_reason_value) {
           write_to_data_file("$zabbix_name Instances_State_Reason_${instance_state_reason_key} $instance_state_reason_value") ;
   }
}
//-------------------------------------------------------------------------//
   
   
//-------------------------------------------------------------------------//
// EC2_DescribeSpotPriceHistory
//-------------------------------------------------------------------------//
function EC2_DescribeSpotPriceHistory($creds)
{

   $zabbix_name = $creds['zabbix_name'] ;

   $xml_object =  exec_ec2_query($creds, 'DescribeSpotPriceHistory') ;
   
   debug_output(__FILE__ . ':' . 
                __FUNCTION__ . ':' . 
                __LINE__ . ':' . 
                "HTTP result received from EC2 for DescribeSpotPriceHistory = " . var_export($xml_object, true) ) ;
   
   // A successful query to the EC2 API will have one or more
   // spotPriceHistorySet - so iterate through that.
   // Each spotPriceHistorySet in turn will have zero, one or more
   // items and each item will have an instanceType and spotPrice.
   foreach ($xml_object->spotPriceHistorySet as $spotPriceHistorySet) {
      if (empty($spotPriceHistorySet)) {
          break ;
      }
      foreach ($spotPriceHistorySet->item as $item) {
          // Print all the data to be sent to Zabbix
          $chars_to_replace = array('/', ' ') ;
          write_to_data_file("$zabbix_name SpotPrice_". 
                             "$item->instanceType" . 
                             str_replace($chars_to_replace, '_' , $item->productDescription) . 
                             " " . $item->spotPrice) ;
      }
   }
   
}
//-------------------------------------------------------------------------//
   
   
//-------------------------------------------------------------------------//
// EC2_DescribeAddresses 
//-------------------------------------------------------------------------//
function EC2_DescribeAddresses($creds) 
{

   $zabbix_name = $creds['zabbix_name'] ;

   $xml_object =  exec_ec2_query($creds, 'DescribeAddresses') ;
   
   debug_output(__FILE__ . ':' . 
                __FUNCTION__ . ':' . 
                __LINE__ . ':' . 
                "HTTP result received from EC2 for DescribeAddresses = " . var_export($xml_object, true) ) ;
   
   // Initialize counters
   $ip_address_count = 0 ;
   $ip_address_used_by_instance_count = 0 ;
	 $ip_address_vpc = 0;
   $ip_address_standard = 0;
   
   // A successful query to the EC2 API will have one or more
   // addressesSet - so iterate through that.
   // Each addressesSet in turn will have zero, one or more
   // items containing a publicIP.
   foreach ($xml_object->addressesSet as $addressesSet) {
      if (empty($addressesSet)) {
          break ;
      }
      foreach ($addressesSet->item as $item) {
               $ip_address_count++ ;
               if (!empty($item->instanceId)) {
                   $ip_address_used_by_instance_count++ ;
               }
							 if ($item->domain == 'vpc') {
									 $ip_address_vpc++;
							 }
							 if ($item->domain == 'standard') {
									 $ip_address_standard++;
							 }
      }
   }
      
   
   
   // Print all the data to be sent to Zabbix
   write_to_data_file("$zabbix_name IP_Addresses_Total $ip_address_count") ;
   write_to_data_file("$zabbix_name IP_Addresses_Assigned $ip_address_used_by_instance_count") ;
   write_to_data_file("$zabbix_name IP_Addresses_Unassigned " .  ($ip_address_count - $ip_address_used_by_instance_count)) ;
   write_to_data_file("$zabbix_name IP_Addresses_VPC $ip_address_vpc");
   write_to_data_file("$zabbix_name IP_Addresses_Standard $ip_address_standard");
}   
//-------------------------------------------------------------------------//

   
//-------------------------------------------------------------------------//
// EC2_DescribeVpcs 
//-------------------------------------------------------------------------//
function EC2_DescribeVpcs($creds) 
{

   $zabbix_name = $creds['zabbix_name'] ;

   $xml_object =  exec_ec2_query($creds, 'DescribeVpcs') ;

   debug_output(__FILE__ . ':' .
                __FUNCTION__ . ':' .
                __LINE__ . ':' .
                "HTTP result received from EC2 for DescribeVpcs = " . var_export($xml_object, true) ) ;

	$vpc_count = 0;
	$vpc_available = 0;
	foreach ($xml_object->vpcSet as $vpcSet) {
		if (empty($vpcSet)) {
		break;
		}	
		foreach ($vpcSet->item as $item) {
			$vpc_count++;
			if ($item->state == 'available') {
				$vpc_available++;
			}
		}
	}

	// Print all the data to be sent to Zabbix
	write_to_data_file("$zabbix_name VPC_Count_Total $vpc_count");
	write_to_data_file("$zabbix_name VPC_Available_Total $vpc_available");

}
 
//-------------------------------------------------------------------------//
// EC2_DescribeVpnConnections
//-------------------------------------------------------------------------//
function EC2_DescribeVpnConnections($creds) 
{

   $zabbix_name = $creds['zabbix_name'] ;

   $xml_object =  exec_ec2_query($creds, 'DescribeVpnConnections') ;

   debug_output(__FILE__ . ':' .
                __FUNCTION__ . ':' .
                __LINE__ . ':' .
                "HTTP result received from EC2 for DescribeVpnConnections = " . var_export($xml_object, true) ) ;

	//print_r($xml_object);
	$vpn_connection_count = 0;
	$vpn_connection_available = 0;
	foreach ($xml_object->vpnConnectionSet->item as $vpnConnectionSet) {
		if(empty($vpnConnectionSet)){
		break;
		}
		foreach($vpnConnectionSet->vpnConnectionId as $vpnConnectionId){
			$vpn_connection_count++;
		}	
		foreach($vpnConnectionSet->state as $state){
			if($state == 'available'){
				$vpn_connection_available++;
			}
		}
	}

	// Print all the data to be sent to Zabbix
	write_to_data_file("$zabbix_name VPN_Connection_Count_Total $vpn_connection_count");
	write_to_data_file("$zabbix_name VPN_Connection_Available $vpn_connection_available");
}

//-------------------------------------------------------------------------//
// EC2_DescribeCustomerGateways
//-------------------------------------------------------------------------//
function EC2_DescribeCustomerGateways($creds) 
{

   $zabbix_name = $creds['zabbix_name'] ;

   $xml_object =  exec_ec2_query($creds, 'DescribeCustomerGateways') ;

   debug_output(__FILE__ . ':' .
                __FUNCTION__ . ':' .
                __LINE__ . ':' .
                "HTTP result received from EC2 for DescribeCustomerGateways = " . var_export($xml_object, true) ) ;

	//print_r($xml_object);
	$customer_gateway_count = 0;
	$customer_gateway_available = 0;
	foreach($xml_object->customerGatewaySet->item as $customerGatewaySet){
		if(empty($customerGatewaySet)){
		break;
		}
		foreach($customerGatewaySet->customerGatewayId as $customerGatewayId){
			$customer_gateway_count++;
		}
		foreach($customerGatewaySet->state as $customerGatewayState){
			if($customerGatewayState == 'available'){
				$customer_gateway_available++;
			}
		}
	}

	// Print all the data to be sent to Zabbix
	write_to_data_file("$zabbix_name Customer_Gateway_Count_Total $customer_gateway_count");
	write_to_data_file("$zabbix_name Customer_Gateway_Available $customer_gateway_available");
	
}
//-------------------------------------------------------------------------//
// EC2_DescribeAvailabilityZones
//-------------------------------------------------------------------------//
function EC2_DescribeAvailabilityZones($creds) 
{

   $zabbix_name = $creds['zabbix_name'] ;

   $xml_object =  exec_ec2_query($creds, 'DescribeAvailabilityZones') ;

   debug_output(__FILE__ . ':' .
                __FUNCTION__ . ':' .
                __LINE__ . ':' .
                "HTTP result received from EC2 for DescribeAvailabilityZones = " . var_export($xml_object, true) ) ;

	//print_r($xml_object);
	$az_count = 0;
	$az_available_count = 0;

	foreach($xml_object->availabilityZoneInfo->item as $availabilityZoneInfo){
		if(empty($availabilityZoneInfo)){
		break;
		}
		foreach($availabilityZoneInfo->zoneName as $zoneName){
			$az_count++;
		}
		foreach($availabilityZoneInfo->zoneState as $zoneState){
			if($zoneState == 'available'){
			$az_available_count++;
			}
		}
	}
	// Print all the data to be sent to Zabbix
	write_to_data_file("$zabbix_name Availability_Zone_Count_Total $az_count");
	write_to_data_file("$zabbix_name Availability_Zone_Count_Available $az_available_count");
}
//-------------------------------------------------------------------------//
// EC2_DescribeReservedInstances 
//-------------------------------------------------------------------------//
function EC2_DescribeReservedInstances($creds) 
{

   $zabbix_name = $creds['zabbix_name'] ;

   $xml_object =  exec_ec2_query($creds, 'DescribeReservedInstances') ;
   
   debug_output(__FILE__ . ':' . 
                __FUNCTION__ . ':' . 
                __LINE__ . ':' . 
                "HTTP result received from EC2 for DescribeReservedInstances = " . var_export($xml_object, true) ) ;
   
   // Initialize counters
   $total_reserved_instance_count = 0 ;

   $reserved_instance_count_array = array() ;
   
   // A successful query to the EC2 API will have one or more
   // reservedInstancesSet - so iterate through that.
   // Each addressesSet in turn will have zero, one or more
   // items containing a publicIP.
   foreach ($xml_object->reservedInstancesSet as $reservedInstancesSet) {
      if (empty($reservedInstancesSet)) {
          break ;
      }
      foreach ($reservedInstancesSet->item as $item) {
         $instanceType = strtolower($item->instanceType) ;
         $reserved_instance_count_array["$instanceType"]++ ;
      }
   }
   
   // Print all the data so that it can be sent to Zabbix
   foreach ($reserved_instance_count_array as $key=>$value) {
            write_to_data_file("$zabbix_name Reserved_Instances_Type_${key} $value") ;
            $total_reserved_instance_count += $value ;
   }
   
   write_to_data_file("$zabbix_name Reserved_Instances_Total $total_reserved_instance_count") ;
}
//-------------------------------------------------------------------------//
   
   
//-------------------------------------------------------------------------//
// EC2_DescribeSnapshots
//-------------------------------------------------------------------------//
function EC2_DescribeSnapshots($creds)
{

   $zabbix_name = $creds['zabbix_name'] ;

   $xml_object =  exec_ec2_query($creds, 'DescribeSnapshots') ;
   
   debug_output(__FILE__ . ':' . 
                __FUNCTION__ . ':' . 
                __LINE__ . ':' . 
                "HTTP result received from EC2 for DescribeSnapshots = " . var_export($xml_object, true) ) ;
   
   // Initialize counters
   $snapshots_array = array("All" => array( "Snapshots_All_Total" => 0,
                                            "Snapshots_All_size_GB" => 0,
                                            "Snapshots_All_Status_pending" => 0,
                                            "Snapshots_All_Status_completed" => 0,
                                            "Snapshots_All_Status_error"     => 0
                                          ) ,
                            "self" => array( "Snapshots_self_Total" => 0,
                                             "Snapshots_self_size_GB" => 0,
                                             "Snapshots_self_Status_pending" => 0,
                                             "Snapshots_self_Status_completed" => 0,
                                             "Snapshots_self_Status_error"     => 0
                                           ) ,
                            "amazon" => array( "Snapshots_amazon_Total" => 0,
                                               "Snapshots_amazon_size_GB" => 0,
                                               "Snapshots_amazon_Status_pending" => 0,
                                               "Snapshots_amazon_Status_completed" => 0,
                                               "Snapshots_amazon_Status_error"     => 0
                                             ) ,
                            "other" => array( "Snapshots_other_Total" => 0,
                                              "Snapshots_other_size_GB" => 0,
                                              "Snapshots_other_Status_pending" => 0,
                                              "Snapshots_other_Status_completed" => 0,
                                              "Snapshots_other_Status_error"     => 0
                                            )
                          ) ;
   
   // A successful query to the EC2 API will have one or more
   // snapshotSet - so iterate through that.
   // Each snapshotSet in turn will have zero, one or more
   // snapshotSets  and each snapshotSet will have zero or more
   // Amazon EC2 snapshots.
   foreach ($xml_object->snapshotSet as $snapshotSet) {
      if (empty($snapshotSet)) {
          break ;
      }
      foreach ($snapshotSet->item as $snapshot) {
   
                   $snapshot_status = $snapshot->status ;
                   switch ($snapshot->ownerAlias) {
                          case "amazon":
                                         $owner = $snapshot->ownerAlias ;
                                         break ;
                          case "self":
                                         $owner = $snapshot->ownerAlias ;
                                         break ;
                          default:
                                         $owner = "other" ;
                                         break ;
                   }
   
                   $snapshots_array["All"]["Snapshots_All_Total"]++  ;
                   $snapshots_array["$owner"]["Snapshots_${owner}_Total"]++  ;
                   $snapshots_array["All"]["Snapshots_All_size_GB"] += $snapshot->volumeSize  ;
                   $snapshots_array["$owner"]["Snapshots_${owner}_size_GB"] += $snapshot->volumeSize  ;
                   $snapshots_array["All"]["Snapshots_All_Status_${snapshot_status}"]++  ;
                   $snapshots_array["$owner"]["Snapshots_${owner}_Status_${snapshot_status}"]++  ;
   
      }
   }
   
   // Print all the data so that it can be sent to Zabbix
   foreach ($snapshots_array as $owner_name=>$owner_data_array) {
            foreach ($owner_data_array as $key => $value) {
                     write_to_data_file("$zabbix_name ${key} $value") ;
            }
   }
}
//-------------------------------------------------------------------------//
   
   
   
   
//-------------------------------------------------------------------------//
// EC2_DescribeVolumes 
//-------------------------------------------------------------------------//
function EC2_DescribeVolumes($creds)
{

   $zabbix_name = $creds['zabbix_name'] ;

   $xml_object =  exec_ec2_query($creds, 'DescribeVolumes') ;
   
   debug_output(__FILE__ . ':' . 
                __FUNCTION__ . ':' . 
                __LINE__ . ':' . 
                "HTTP result received from EC2 for DescribeVolumes = " . var_export($xml_object, true) ) ;
   
   // Initialize counters
   $volume_status_array = array("creating" => array( "Volumes_Status_creating" => 0,
                                                     "Volumes_Status_creating_Total_Size_GB" => 0
                                                   ) ,
                                "available" => array( "Volumes_Status_available" => 0,
                                                      "Volumes_Status_available_Total_Size_GB" => 0
                                                    ) ,
                                "other" => array( "Volumes_Status_other" => 0,
                                                      "Volumes_Status_other_Total_Size_GB" => 0
                                                    )
                               ) ;
   $volume_attachment_status_array = array("attaching" => array( "Volumes_attaching" => 0,
                                                                "Volumes_attaching_Total_Size_GB" => 0
                                                              ) ,
                                           "attached" => array( "Volumes_attached" => 0,
                                                                 "Volumes_attached_Total_Size_GB" => 0
                                                               ) ,
                                           "detaching" => array( "Volumes_detaching" => 0,
                                                             "Volumes_detaching_Total_Size_GB" => 0
                                                           ),
                                           "detached" => array( "Volumes_detached" => 0,
                                                             "Volumes_detached_Total_Size_GB" => 0
                                                           )
                                          ) ;
   
   // A successful query to the EC2 API will have one or more
   // volumeSets - so iterate through that.
   // Each volumeSet in turn will have zero, one or more
   // items containing a volume with volumeId.
   foreach ($xml_object->volumeSet as $volumeSet) {
      if (empty($volumeSet)) {
          break ;
      }
      foreach ($volumeSet->item as $volumeId) {
   
               $volume_status = $volumeId->status ;
               $volume_size = $volumeId->size ;
               switch ($volume_status) {
                      case "creating":
                             break ;
                      case "available":
                             break ;
                      default:
                             $volume_status = "other" ;
                             break ;
               }
   
               $volume_status_array["$volume_status"]["Volumes_Status_${volume_status}"]++  ;
               $volume_status_array["$volume_status"]["Volumes_Status_${volume_status}_Total_Size_GB"] += $volume_size  ;
               foreach ($volumeId->attachmentSet as $attachmentSet) {
                       if (empty($volumeSet)) {
                           break ;
                       }
   
                       $attachment_status = $attachmentSet->item->status ;
   
                       $volume_attachment_status_array["$attachment_status"]["Volumes_${attachment_status}"]++  ;
                       $volume_attachment_status_array["$attachment_status"]["total_${attachment_status}_Total_Size_GB"] += $volume_size  ;
               }
   
      }
   }
   
   // Print all the data so that it can be sent to Zabbix
   foreach ($volume_status_array as $volume_status=>$volume_status_data_array) {
            foreach ($volume_status_data_array as $key => $value) {
                     write_to_data_file("$zabbix_name $key $value") ;
            }
   }
   
   foreach ($volume_attachment_status_array as $volume_attachment_status=>$volume_attachment_status_data_array) {
            foreach ($volume_attachment_status_data_array as $key => $value) {
                     write_to_data_file("$zabbix_name $key $value") ;
            }
   }
}   
//-------------------------------------------------------------------------//

// Get data collection end time (we will use this to compute the total data collection time)
$start_time = time() ;

EC2_DescribeImages($creds) ;
EC2_DescribeInstances($creds) ;
EC2_DescribeAddresses($creds) ;
EC2_DescribeVpcs($creds) ;
EC2_DescribeVpnConnections($creds);
EC2_DescribeCustomerGateways($creds);
EC2_DescribeAvailabilityZones($creds);
EC2_DescribeReservedInstances($creds) ;
EC2_DescribeSnapshots($creds) ;
EC2_DescribeVolumes($creds) ;
EC2_DescribeSpotPriceHistory($creds) ;

// Get data collection end time (we will use this to compute the total data collection time)
$end_time = time() ;
$data_collection_time = $end_time - $start_time ;
write_to_data_file("$zabbix_name EC2_Plugin_Data_collection_time $data_collection_time") ;

write_to_data_file("$zabbix_name EC2_Plugin_Version $command_version") ;
write_to_data_file("$zabbix_name EC2_Plugin_Checksum $md5_checksum_string") ;

fclose($data_file_handle) ;

exec("zabbix_sender -vv -z 127.0.0.1 -i $data_file_name 2>&1", $log_file_data) ;

foreach ($log_file_data as $log_line)
{
   write_to_log_file("$log_line\n") ;
}

fclose($log_file_handle) ;

exit ;

?>
