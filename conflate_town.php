#!/usr/bin/env php
<?php

$help = "

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
$db = new SQLite3('osm_data/osm_addresses.sqlite');
$db->loadExtension('mod_spatialite.so');
$inputDoc = new DOMDocument();
$inputDoc->load($file);

require_once("src/AddressConflator.php");
$conflator = new AddressConflator($db, $verbose);
$conflator->conflate($inputDoc);

$conflator->nonMatchesDoc->save("data_files_to_import/conflated/".basename($file, '.osm')."-no-match.osm");
$conflator->conflictsDoc->save("data_files_to_import/conflated/".basename($file, '.osm')."-tag-conflict.osm");
$conflator->reviewMultiplesDoc->save("data_files_to_import/conflated/".basename($file, '.osm')."-review-multiple.osm");
$conflator->reviewDistancesDoc->save("data_files_to_import/conflated/".basename($file, '.osm')."-review-distance.osm");
$conflator->matchesDoc->save("data_files_to_import/conflated/".basename($file, '.osm')."-matches.osm");
  $conflator->duplicatesInDifferentTownsDoc->save("data_files_to_import/conflated/".basename($file, '.osm')."-duplicates-in-different-towns.osm");
