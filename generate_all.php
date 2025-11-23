#!/usr/bin/env php
<?php

$help = "

Generate new output files in data_files_to_import/draft/ for every
input file in town_e911_address_points/

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

$input_files = scandir(__DIR__ . '/town_e911_address_points');
foreach ($input_files as $input_file) {
  if (preg_match('/(.*\/)?e911_address_points_(.+)\.geojson$/i', $input_file, $file_matches)) {
    $input_path = __DIR__ . '/town_e911_address_points/' . $input_file;
    $output_path = __DIR__ . '/data_files_to_import/all-points/'.$file_matches[2].'_addresses.osm';
    if ($verbose) {
      fwrite(STDERR, "Processing $input_file\n");
    }
    $command = __DIR__ . '/generate_osm_file_from_e911_geojson.php ';
    if ($verbose) {
      $command .= '-v ';
    }
    $command .= $input_path.' 2>&1 > '.$output_path;
    $output = shell_exec($command);
    if ($output) {
      fwrite(STDERR, $output);
    }
  }
}
