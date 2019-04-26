#!/usr/local/bin/php
<?php
require_once('config.php');
/*
 ./rtl_433 -F json:433mhz_log.json -M utc -M level -M newmodel -R 44 -R 68 -R 03 && tail -f 433mhz_log.json |php ./parser.php 
*/
$knownDevices = array();
$devices = array();
$lastLine = '';

function doDebug($device, $message){
	if(DEBUG){
		$dateStamp = date("c");
		echo "\e[92m[ {$dateStamp} ] \e[96m{$device}\e[0m $message\r\n";
	}
}

function doDiscovery($hash, $reason){
	global $devices,$knownDevices;

	if($knownDevices[$hash]['model']=="Prologue-TH"){
			doDebug($devices[$hash]['name'], "Doing Discovery");
			$topic1 = 'homeassistant/sensor/temp_'.$devices[$hash]['name'].'/config';
			$payload1 = '{"device_class": "temperature", "name": "'.$devices[$hash]['name'].' Temperature", "state_topic": "homeassistant/sensor/'.$devices[$hash]['name'].'/state", "unit_of_measurement": "°C", "value_template": "{{value_json.temperature}}" }';
			exec('/usr/local/bin/mosquitto_pub -h '.CONFIG_MQTT_HOST.' -u '.CONFIG_MQTT_USER.' -P '.CONFIG_MQTT_PASS.' -t '.$topic1.' -m "'.addslashes($payload1).'"');
			
			$topic2 = 'homeassistant/sensor/hum_'.$devices[$hash]['name'].'/config';
			$payload2 = '{"device_class": "humidity", "name": "'.$devices[$hash]['name'].' Humidity", "state_topic": "homeassistant/sensor/'.$devices[$hash]['name'].'/state", "unit_of_measurement": "%", "value_template": "{{value_json.humidity}}" }';
			exec('/usr/local/bin/mosquitto_pub -h '.CONFIG_MQTT_HOST.' -u '.CONFIG_MQTT_USER.' -P '.CONFIG_MQTT_PASS.' -t '.$topic2.' -m "'.addslashes($payload2).'"');
	}
}

function doUpdate($hash){
	global $devices, $knownDevices;
	
	if(@$knownDevices[$hash]['model']=="Prologue-TH"){
		$topic = 'homeassistant/sensor/'.$devices[$hash]['name'].'/state';
		$payload = '{ "temperature": "'.$devices[$hash]['temperature'].'", "humidity": "'.$devices["$hash"]['humidity'].'" }';
		exec('/usr/local/bin/mosquitto_pub -h '.CONFIG_MQTT_HOST.' -u '.CONFIG_MQTT_USER.' -P '.CONFIG_MQTT_PASS.' -t '.$topic.' -m "'.addslashes($payload).'"');
		doDebug($devices[$hash]['name'], "Temperature: {$devices[$hash]['temperature']}, Humidity: {$devices[$hash]['humidity']}");
	}

	if(@$knownDevices[$hash]['model']=="Kerui-Security"){
		exec('/usr/local/bin/mosquitto_pub -h '.CONFIG_MQTT_HOST.' -u '.CONFIG_MQTT_USER.' -P '.CONFIG_MQTT_PASS.' -t homeassistant/motion/'.$devices[$hash]['name'].' -m "ON"');
		doDebug($devices[$hash]['name'], "Motion Detected");
	}

	if(@$knownDevices[$hash]['model']=="CurrentCost-TX"){
		exec('/usr/local/bin/mosquitto_pub -h '.CONFIG_MQTT_HOST.' -u '.CONFIG_MQTT_USER.' -P '.CONFIG_MQTT_PASS.' -t homeassistant/power/'.$devices[$hash]['name'].' -m "'.$devices[$hash]['power0'].'"');
		doDebug($devices[$hash]['name'], "{$devices[$hash]['power0']} watts");
	}

}

function trackDevices($rfData){
	global $knownDevices, $devices;
	$hash=hash('md4', @$rfData['model'].@$rfData['subtype'].@$rfData['channel'].@$rfData['id'].@$rfData['code']);

	if($rfData['model']=="Prologue-TH"){
		$uniqueID = $rfData['channel'].'_'.$rfData['subtype'].'_'.$rfData['id'];
		$devices[$hash]= array(
			'name' => $uniqueID,
			'temperature'=> $rfData['temperature_C'],
			'humidity'=>$rfData['humidity'],
			'lastUpdated'=> date('c')
			);
	}

	if($rfData['model']=="Kerui-Security"){
		$devices[$hash]= array(
			'name' => $rfData['id'],
			'lastUpdated'=> date('c')
			);
	}

	if($rfData['model']=="CurrentCost-TX"){
		$devices[$hash]= array(
			'name' => $rfData['id'],
			'power0' => $rfData['power0'],
			'lastUpdated'=> date('c')
			);
	}

	if(array_key_exists($hash, $knownDevices)){
		if( (time() - $knownDevices[$hash]['timestamp']) > CONFIG_DISCOVERY_TIMEOUT ){
			doDiscovery($hash,'timeout');
		}
	} else {
		//unknown device so do discovery
		$knownDevices[$hash]['model']=$rfData['model'];
		doDiscovery($hash,'new device');
	}
	$knownDevices[$hash]['timestamp']=time();
	doUpdate($hash);
	return($knownDevices);
}

//stream_set_blocking(STDIN, 0);
while (false !== ($line = fgets(STDIN))) {
	if($lastLine != $line){
		$rfData=json_decode($line, true);
		$knownDevices = trackDevices($rfData);
		doUpdate($rfData);
		$lastLine = $line;
}
}
