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

$outputFile = "data_files_to_import/conflated-changes/".basename($file, '.osm')."-no-match.osm";
if ($conflator->nonMatchesDoc->documentElement->childElementCount) {
  $conflator->nonMatchesDoc->save($outputFile);
} elseif (file_exists($outputFile)) {
  unlink($outputFile);
}

$outputFile = "data_files_to_import/conflated-changes/".basename($file, '.osm')."-tag-conflict.osm";
if ($conflator->conflictsDoc->documentElement->childElementCount) {
  $conflator->conflictsDoc->save($outputFile);
} elseif (file_exists($outputFile)) {
  unlink($outputFile);
}

$outputFile = "data_files_to_import/conflated-existing/".basename($file, '.osm')."-review-multiple.osm";
if ($conflator->reviewMultiplesDoc->documentElement->childElementCount) {
  $conflator->reviewMultiplesDoc->save($outputFile);
} elseif (file_exists($outputFile)) {
  unlink($outputFile);
}

$outputFile = "data_files_to_import/conflated-existing/".basename($file, '.osm')."-review-distance.osm";
if ($conflator->reviewDistancesDoc->documentElement->childElementCount) {
  $conflator->reviewDistancesDoc->save($outputFile);
} elseif (file_exists($outputFile)) {
  unlink($outputFile);
}

$outputFile = "data_files_to_import/conflated-existing/".basename($file, '.osm')."-matches.osm";
if ($conflator->matchesDoc->documentElement->childElementCount) {
  $conflator->matchesDoc->save($outputFile);
} elseif (file_exists($outputFile)) {
  unlink($outputFile);
}

$outputFile = "data_files_to_import/conflated-existing/".basename($file, '.osm')."-duplicates-in-different-towns.osm";
if ($conflator->duplicatesInDifferentTownsDoc->documentElement->childElementCount) {
  $conflator->duplicatesInDifferentTownsDoc->save($outputFile);
} elseif (file_exists($outputFile)) {
  unlink($outputFile);
}
