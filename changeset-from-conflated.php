#!/usr/bin/env php
<?php

$help = "
Create an OsmChange file that combines the current state of objects (nodes, ways)
in the local OSM SQLite database with the address changes in the input file.
The resulting OsmChange file will ONLY change the address tags.

". $argv[0] . " [-hv] [--help] [--verbose] <file.osm>

  -h --help           Show this help
  -v --verbose        Print errors at the end.

  <file.osm>      The input osm file that was already prepared from the e911 data.

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
$inputDoc = new DOMDocument();
$inputDoc->load($file);
$osmDoc = new DOMDocument();
$osmDoc->load('osm_data/osm_addresses.osm');

require_once("src/OsmChangeConflator.php");
$conflator = new OsmChangeConflator($osmDoc, $verbose);
$conflator->conflate($inputDoc);

$outputFile = "data_files_to_import/changesets/".basename($file, '.osm').".osc";
if ($conflator->changeDoc->documentElement->childElementCount) {
  $conflator->changeDoc->save($outputFile);
} elseif (file_exists($outputFile)) {
  unlink($outputFile);
}
