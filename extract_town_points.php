#!/usr/bin/env php
<?php

$help = "

Separate a state-wide `VT_Data_-_E911_Site_Locations_(address_points).geojson`
file into per-town files in `town_e911_address_points/`

". $argv[0] . " [-hv] [--help] [--verbose]

  -h --help           Show this help
  -v --verbose        Print status output.

  <file.geojson>      The input geojson file.

";

#options
$options = getopt("hv", ["help", "verbose", "output-type::"], $reset_index);
if ($options === FALSE || isset($options["h"]) || isset($options["help"])) {
  print $help;
  exit(1);
}
$verbose = false;
if (isset($options['v']) || isset($options['verbose'])) {
  $verbose = true;
}

# file
$pos_args = array_slice($argv, $reset_index);
if (!count($pos_args)) {
  fwrite(STDERR, "You must specify an input file.");
  fwrite(STDERR, $help);
  exit(2);
}
$file = $pos_args[0];
if (!file_exists($file)) {
  fwrite(STDERR, "File $file does not exist.");
  fwrite(STDERR, $help);
  exit(3);
}
if (!is_readable($file)) {
  fwrite(STDERR, "File $file is not readable.");
  fwrite(STDERR, $help);
  exit(3);
}

if ($verbose) {
  fwrite(STDERR, "Loading $file\n");
}

ini_set('memory_limit', '4G');

$towns = [];
$i = 0;
$data = json_decode(file_get_contents($file));
if (is_object($data) && $data->type == "FeatureCollection") {
  foreach ($data->features as $feature) {
    if (is_object($feature) && $feature->type == "Feature") {
      $i++;

      $town = ucwords(strtolower($feature->properties->Inc_Muni));
      if (empty($town)) {
        die ("Missing Inc_Muni in ".json_encode($feature));
      }
      if (!isset($towns[$town])) {
        $towns[$town] = [];
      }
      if (isset($towns[$town][$feature->properties->ESITEID])) {
        fwrite(STDERR, "\nDuplicate ESITEID ".$feature->properties->ESITEID." in $town\n");
        continue;
      }
      $towns[$town][$feature->properties->ESITEID] = json_encode($feature);

      if ($verbose && $i % 3000 == 0) {
        fwrite(STDERR, ".");
      }
    }
  }
} else {
  die("Expected a FeatureCollection got a ".$data->type." feature.");
}


$townFiles = [];
$i = 0;
foreach ($towns as $town => $features) {
  fwrite(STDERR, count($features) . " features in $town\n");
  ksort($features);
  $i++;
  $townFile = openTownFile($town);
  fwrite($townFile, implode(",\n", $features));
  closeTownFile($townFile);

  if ($verbose && $i % 23 == 0) {
    fwrite(STDERR, ".");
  }
}

if ($verbose) {
  fwrite(STDERR, "\n");
}

function openTownFile($town) {
  $file = fopen(__DIR__ . '/town_e911_address_points/e911_address_points_' . str_replace(' ', '_', strtolower($town)) . '.geojson', 'w+');
  fwrite($file, '{
    "type": "FeatureCollection",
    "name": "FS_VCGI_OPENDATA_Emergency_SiteStructureAddressPoint_point_SP_v1 - ' . $town . '",
    "crs": {
        "type": "name",
        "properties": {
            "name": "urn:ogc:def:crs:OGC:1.3:CRS84"
        }
    },
    "features": [
');
  return $file;
}

function closeTownFile($file) {
  fwrite($file, '
]
}
');
  fclose($file);
}
