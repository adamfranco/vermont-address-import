#!/usr/bin/env php
<?php

$help = "

". $argv[0] . " [-hv] [--help] [--verbose] <file.osm>

  -h --help           Show this help
  -v --verbose        Print errors at the end.

  <file.osm>      The input file of osm data.

";

#options
$options = getopt("hv", ["help", "verbose"], $reset_index);
if ($options === FALSE || isset($options["h"]) || isset($options["help"])) {
  fwrite(STDERR, $help);
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

chdir(__dir__);

if (!is_readable("osm_data/osm_addresses.sqlite")) {
  fwrite(STDERR, "Database file at osm_data/osm_addresses.sqlite is not readable.");
  fwrite(STDERR, $help);
  exit(3);
}

// Inputs
unlink('osm_data/osm_addresses.sqlite');
touch('osm_data/osm_addresses.sqlite');
$db = new SQLite3('osm_data/osm_addresses.sqlite');
$db->loadExtension('mod_spatialite.so');
$db->query("SELECT InitSpatialMetaData();");

$db->query("CREATE TABLE addresses (
  osm_type VARCHAR(10) NOT NULL,
  id INT NOT NULL,
  housenumber VARCHAR(255),
  street VARCHAR(255),
  place VARCHAR(255),
  unit VARCHAR(255),
  city VARCHAR(255),
  state VARCHAR(255),
  postcode VARCHAR(255),
  esiteid INT,
  PRIMARY KEY(osm_type,id)
);");
$db->query("SELECT AddGeometryColumn('addresses', 'geom', 4326, 'POINT', 'XY');");

$inputDoc = new DOMDocument();
$inputDoc->load($file);

$i = 0;
$increment = floor($inputDoc->documentElement->childNodes->count() / 100);
foreach ($inputDoc->documentElement->childNodes as $inputNode) {
  $i++;
  if ($i % $increment === 0) {
    print ".";
  }
  if ($inputNode->nodeType == XML_ELEMENT_NODE && in_array($inputNode->nodeName, ['node', 'way', 'relation'])) {
    $address = extractAddress($inputNode);
    if (!empty($address)) {
      $lon = getLon($inputNode);
      $lat = getLat($inputNode);
      if (empty($inputNode->getAttribute('id'))) {

      }
      $query = "INSERT INTO
        addresses (osm_type, id, geom, housenumber, street, place, unit, city, state, postcode, esiteid)
      VALUES (
        '" . SQLite3::escapeString($inputNode->nodeName) . "',
        " . $inputNode->getAttribute('id') . ",
        MakePoint($lon, $lat, 4326),
        '" . SQLite3::escapeString($address['addr:housenumber']) ."',
        '" . SQLite3::escapeString($address['addr:street']) ."',
        '" . SQLite3::escapeString($address['addr:place']) ."',
        '" . SQLite3::escapeString($address['addr:unit']) ."',
        '" . SQLite3::escapeString($address['addr:city']) ."',
        '" . SQLite3::escapeString($address['addr:state']) ."',
        '" . SQLite3::escapeString($address['addr:postcode']) . "',
        '" . SQLite3::escapeString($address['ref:vcgi:esiteid']) . "'
      )";
      if (!$db->exec($query)) {
          print "ERROR RUNNING: \n$query\n";
      }
    }
  }
}
print "\n";

function getLon(DOMElement $inputNode) {
  if ($inputNode->hasAttribute('lon')) {
    return $inputNode->getAttribute('lon');
  }
  foreach ($inputNode->getElementsByTagName('center') as $centerNode) {
    return $centerNode->getAttribute('lon');
  }
  throw new Exception("Couldn't get a lon from ".$inputDoc->saveXML($inputNode));
}
function getLat(DOMElement $inputNode) {
  if ($inputNode->hasAttribute('lat')) {
    return $inputNode->getAttribute('lat');
  }
  foreach ($inputNode->getElementsByTagName('center') as $centerNode) {
    return $centerNode->getAttribute('lat');
  }
  throw new Exception("Couldn't get a lat from ".$inputDoc->saveXML($inputNode));
}

function extractAddress(DOMElement $inputNode) {
  $result = [
    'addr:housenumber' => '',
    'addr:street' => '',
    'addr:place' => '',
    'addr:unit' => '',
    'addr:city' => '',
    'addr:state' => '',
    'addr:postcode' => '',
    'ref:vcgi:esiteid' => '',
  ];
  foreach ($inputNode->childNodes as $child) {
    if ($child->nodeName == 'tag' && preg_match('/^addr:.+/', $child->getAttribute('k'))) {
      $result[$child->getAttribute('k')] = $child->getAttribute('v');
    }
    if ($child->nodeName == 'tag' && $child->getAttribute('k') == 'ref:vcgi:esiteid') {
      $result[$child->getAttribute('k')] = $child->getAttribute('v');
    }
  }
  return $result;
}
