#!/usr/local/bin/php
<?php
require_once('config.php');

$knownDevices = array();
$devices = array();
$lastLine = '';

function doDebug($device, $message){
	if(DEBUG){
		$dateStamp = date("c");
		echo "\e[92m[ {$dateStamp} ] \e[96m{$device}\e[0m $message\r\n";
	}
}

function doMosquittoPub($topic, $payload) {
	exec(CONFIG_MOSQUITTO_PUB.' -h '.CONFIG_MQTT_HOST.' -u '.CONFIG_MQTT_USER.' -P '.CONFIG_MQTT_PASS.
		' -t '.$topic.' -m "'.addslashes($payload).'"');
}

function doDiscovery($hash, $reason){
	global $devices,$knownDevices;

	if($knownDevices[$hash]['model']=="Prologue-TH"){
			doDebug($devices[$hash]['name'], "Doing Discovery");
			$topic = 'homeassistant/sensor/temp_'.$devices[$hash]['name'].'/config';
			$payload = '{"device_class": "temperature", "name": "'.$devices[$hash]['name'].' Temperature", "state_topic": "homeassistant/sensor/'.$devices[$hash]['name'].'/state", "unit_of_measurement": "°C", "value_template": "{{value_json.temperature}}" }';
			doMosquittoPub($topic, $payload);

			$topic = 'homeassistant/sensor/hum_'.$devices[$hash]['name'].'/config';
			$payload = '{"device_class": "humidity", "name": "'.$devices[$hash]['name'].' Humidity", "state_topic": "homeassistant/sensor/'.$devices[$hash]['name'].'/state", "unit_of_measurement": "%", "value_template": "{{value_json.humidity}}" }';
			doMosquittoPub($topic, $payload);
	}
}

function doUpdate($hash){
	global $devices, $knownDevices;
	
	if(@$knownDevices[$hash]['model']=="Prologue-TH"){
		$topic = 'homeassistant/sensor/'.$devices[$hash]['name'].'/state';
		$payload = '{ "temperature": "'.$devices[$hash]['temperature'].'", "humidity": "'.$devices["$hash"]['humidity'].'" }';
		doDebug($devices[$hash]['name'], "Temperature: {$devices[$hash]['temperature']}, Humidity: {$devices[$hash]['humidity']}");
	}

	if(@$knownDevices[$hash]['model']=="Kerui-Security"){
		if($knownDevices[$hash]['model']=='motion'){//usually motion, but seems to be other things caught by this.
			$topic='homeassistant/motion/'.$devices[$hash]['name'];
			$payload='ON';
			doDebug($devices[$hash]['name'], "Motion Detected");
		} else {//anything else handle as a button
			$topic='homeassistant/binary_sensor/'.$devices[$hash]['name'];
			$payload=$devices[$hash]['state'];
		}
	}

	if(@$knownDevices[$hash]['model']=="CurrentCost-TX"){
		$topic='homeassistant/power/'.$devices[$hash]['name'];
		$payload=$devices[$hash]['power0'];
		doDebug($devices[$hash]['name'], "{$devices[$hash]['power0']} watts");
	}
	doMosquittoPub(@$topic, @$payload);
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
			'state' => $rfData['state'],
			'cmd' => $rfData['cmd'],
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
		$lastLine = $line;
}
}
