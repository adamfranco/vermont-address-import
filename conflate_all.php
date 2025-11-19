#!/usr/bin/env php
<?php

$help = "

Conflate all town files in data_files_to_import/all-points/ and write to data_files_to_import/conflated/

". $argv[0] . " [-hv] [--help] [--verbose]

  -h --help           Show this help
  -v --verbose        Print status output.

  --name-range        A range of name prefixes to conflate. Examples:

                      --name-range=-berkshire
                         Conflate addison and everything up to and including
                         berkshire.

                      --name-range=berlin-bolton
                         Conflate berlin, bethel, bloomfield, and bolton.

                      --name-range=norwich-
                        Conflate norwich and everything after.

  --skip-existing     Skip conflating towns where the output files exist.

";

#options
$options = getopt("hv", ["help", "verbose", "name-range::", "skip-existing"], $reset_index);
if ($options === FALSE || isset($options["h"]) || isset($options["help"])) {
  print $help;
  exit(1);
}
$verbose = false;
if (isset($options['v']) || isset($options['verbose'])) {
  $verbose = true;
}

if (isset($options['name-range'])) {
  if (!preg_match('/^\w*-?\w*$/i', $options['name-range'])) {
    print "Invalid name range.\n\n";
    print $help;
    exit(1);
  }
} else {
  $options['name-range'] = NULL;
}

$skip_existing = isset($options['skip-existing']);

$input_files = scandir(__DIR__ . '/data_files_to_import/all-points');
foreach ($input_files as $input_file) {
  if (preg_match('/(.+)_addresses\.osm$/i', $input_file, $file_matches)
    && name_in_range($input_file, $options['name-range'])
    && should_overwrite_output($file_matches[1], $skip_existing)
  ) {
    $input_path = __DIR__ . '/data_files_to_import/all-points/' . $input_file;
    if ($verbose) {
      fwrite(STDERR, "\n---------------------------\nConflating $input_file\n");
    }
    $command = __DIR__ . '/conflate_town.php ';
    if ($verbose) {
      $command .= '-v ';
    }
    $command .= $input_path;

    $descriptorspec = [STDIN, STDOUT, STDOUT];
    $process = proc_open($command, $descriptorspec, $pipes);
    proc_close($process);
  }
}

function name_in_range($filename, $nameRange) {
  if (empty($nameRange)) {
    return TRUE;
  }

  // If we have a real range, get our start and end.
  if (preg_match('/^\w*-\w*$/i', $nameRange)) {
    $range = explode('-', $nameRange);
    $start = $range[0];
    $end = $range[1];
  }
  // If we have just a single name use it for start and end.
  else {
    $start = $nameRange;
    $end = $nameRange;
  }

  // Check for name before start.
  if (!empty($start) && strcasecmp(substr($filename, 0, strlen($start)), $start) < 0) {
    return FALSE;
  }
  // Check for name after end.
  if (!empty($end) && strcasecmp(substr($filename, 0, strlen($end)), $end) > 0) {
    return FALSE;
  }

  // Must be in range.
  return TRUE;
}

function should_overwrite_output($town, $skip_existing) {
  if (!$skip_existing) {
    return TRUE;
  }
  $outputBase = __DIR__ . '/data_files_to_import';
  $fileBase = $town . '_addresses';
  if (!file_exists($outputBase . '/conflated-changes/' . $fileBase . '-tag-conflict.osm')) {
    return TRUE;
  }
  if (!file_exists($outputBase . '/conflated-changes/' . $fileBase . '-no-match.osm')) {
    return TRUE;
  }
  if (!file_exists($outputBase . '/conflated-existing/' . $fileBase . '-matches.osm')) {
    return TRUE;
  }
  if (!file_exists($outputBase . '/conflated-existing/' . $fileBase . '-review-distance.osm')) {
    return TRUE;
  }
  if (!file_exists($outputBase . '/conflated-existing/' . $fileBase . '-review-multiple.osm')) {
    return TRUE;
  }
  if (!file_exists($outputBase . '/conflated-existing/' . $fileBase . '-duplicates-in-different-towns.osm')) {
    return TRUE;
  }
  // If we are skipping existing and all files exist, no need to conflate.
  return FALSE;
}
