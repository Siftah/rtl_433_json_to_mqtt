# What Is It?
`rtl_433_json_to_mqtt.php` is a very simple bit of PHP to parse the JSON formatted output of rtl_433 and pass it into 
MQTT in a format that's usable for Home Assistant ('HASS').

It will do auto-discovery for any temperature sensors as necessary, creating them a unique ID.

Currently it supports just three specific types of sensor format;
- CurrentCost-TX
- Prologue-TH
- Kerui-Security

I'm using this "Digoo DG-R8S" device which works well and is compatible with the Prologue-TH format above: https://www.banggood.com/Digoo-DG-R8S-433MHz-Wireless-Digital-Hygrometer-Thermometer-Weather-Station-Remote-Sensor-p-1139603.html

For the motion detectors I've had success with these KERUI sensors, the nice thing about these is you can power them
either from batteries or direct from USB:
https://www.banggood.com/KERUI-P817-433MHz-Wireless-Ceiling-Curtain-PIR-Detector-Infrared-Sensor-for-Security-Alarm-System-p-1307135.html

https://www.banggood.com/KERUI-P819-Wireless-Intelligent-PIR-Detector-Sensor-433MHz-for-WiFi-GSMPSTN-Auto-Dial-System-p-1307131.html

# How To Use
1. Edit the config.php file to reflect the hostname, username and password for your MQTT host. You may want to enable
or disable the debugging whilst you're there.

2. Launch the rtl_433 process and configure it to spit out the logfile in the correct format the parsing script expects.
Note that in this example I've only enabled the formats (with `-G`) for the sensors I'm using, you will want to change
these if you extend the script.

`screen -S rtl_433 rtl_433 -F json:433mhz_log.json -M utc -M level -M newmodel -R 44 -R 68 -R 03`

The `screen -s rtl_433` part is optional and will just leave the process running inside a screen'd terminal session.

Your `433mhz_log.json` will start filling up with entries like this;
```
{"time" : "2019-04-26 09:31:42", "model" : "Prologue-TH", "subtype" : 9, "id" : 179, "channel" : 1, "battery_ok" : 0, "button" : 0, "temperature_C" : 16.700, "humidity" : 37, "mod" : "ASK", "freq" : 433.920, "rssi" : -5.906, "snr" : 33.228, "noise" : -39.134}
{"time" : "2019-04-26 09:31:51", "model" : "Prologue-TH", "subtype" : 5, "id" : 96, "channel" : 1, "battery_ok" : 1, "button" : 0, "temperature_C" : 20.800, "humidity" : 43, "mod" : "ASK", "freq" : 433.972, "rssi" : -2.932, "snr" : 34.441, "noise" : -37.373}
{"time" : "2019-04-26 09:31:53", "model" : "Prologue-TH", "subtype" : 5, "id" : 43, "channel" : 3, "battery_ok" : 1, "button" : 0, "temperature_C" : 23.000, "humidity" : 42, "mod" : "ASK", "freq" : 433.998, "rssi" : -0.118, "snr" : 37.255, "noise" : -37.373}
{"time" : "2019-04-26 10:09:34", "model" : "CurrentCost-TX", "id" : 77, "power0" : 791, "power1" : 0, "power2" : 0, "mod" : "FSK", "freq1" : 433.964, "freq2" : 433.907, "rssi" : -12.144, "snr" : 17.447, "noise" : -29.591}
{"time" : "2019-04-26 10:09:40", "model" : "CurrentCost-TX", "id" : 77, "power0" : 781, "power1" : 0, "power2" : 0, "mod" : "FSK", "freq1" : 433.964, "freq2" : 433.904, "rssi" : -12.144, "snr" : 17.959, "noise" : -30.103}
{"time" : "2019-04-26 10:09:46", "model" : "Prologue-TH", "subtype" : 5, "id" : 96, "channel" : 1, "battery_ok" : 1, "button" : 0, "temperature_C" : 20.800, "humidity" : 45, "mod" : "ASK", "freq" : 433.972, "rssi" : -1.161, "snr" : 34.963, "noise" : -36.124}
```

3. Now `tail` the logfile and feed it into the parser.

`tail -f 433mhz_log.json|php rtl_433_json_to_mqtt.php`

You should see (if you left debugging on) some output to confirm your sensors are being discovered and then some output
to confirm sensor data is being pushed to MQTT.

```
[ 2019-04-26T12:11:29+02:00 ] 77 769 watts
[ 2019-04-26T12:11:29+02:00 ] 1_9_179 Doing Discovery
[ 2019-04-26T12:11:29+02:00 ] 1_9_179 Temperature: 18.1, Humidity: 37
[ 2019-04-26T12:11:29+02:00 ] 1_5_96 Doing Discovery
[ 2019-04-26T12:11:30+02:00 ] 1_5_96 Temperature: 20.8, Humidity: 45
[ 2019-04-26T12:11:30+02:00 ] 3_5_43 Doing Discovery
[ 2019-04-26T12:11:30+02:00 ] 3_5_43 Temperature: 22.8, Humidity: 42
[ 2019-04-26T12:11:31+02:00 ] 77 796 watts
[ 2019-04-26T12:11:31+02:00 ] 77 829 watts
[ 2019-04-26T12:11:31+02:00 ] 77 803 watts
```

## Configuring Home Assistant
The configuration for the sensors in HASS will be auto created for the temperature sensors, for Current Cost and the
Motion sensors you will need to add entries such as the following:

### Current Cost
The `77` in the below example is the uniqe ID generated each time you change the batteries, you may want to just remove
this if you only have (and only ever intend to have) one Current Cost and don't have any neighbours! ;)
```
sensors:
  - platform: mqtt
    name: currentcost_77
    state_topic: homeassistant/power/77
    unit_of_measurement: "Watts"
    icon: mdi:power-plug
```
### Motion Detectors
For the Kerui Motion sensors, you will need to add them as binary_sensors. In the below example 170889 is the unique id
generated by the sensor, allowing you to keep track of which is which.
```
binary_sensor:
  - platform: mqtt
    name: "motion_sensor_170889"
    state_topic: "homeassistant/motion/170889"
    payload_on: "ON"
    device_class: motion
    off_delay: 5
```
