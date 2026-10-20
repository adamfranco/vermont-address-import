#!/usr/bin/env php
<?php

// The OverPass query for the whole state:
$stateQuery = <<<END
[timeout:90];
(
  area["ISO3166-2"="US-VT"]["admin_level"="4"]["boundary"="administrative"]->.state;
  nwr["addr:housenumber"](area.state);
  nwr["addr:street"](area.state);
  nwr["addr:place"](area.state);
  nwr["addr:city"](area.state);
  nwr["ref:vcgi:esiteid"](area.state);

  // Some objects just over the border into NY that have Vermont addresses in Arlington, VT.
  nwr["addr:housenumber"](43.107886,-73.267879,43.122276,-73.264110);
  nwr["addr:street"](43.107886,-73.267879,43.122276,-73.264110);
  nwr["addr:place"](43.107886,-73.267879,43.122276,-73.264110);
  nwr["addr:city"](43.107886,-73.267879,43.122276,-73.264110);
  nwr["ref:vcgi:esiteid"](43.107886,-73.267879,43.122276,-73.264110);
);
(._;);
out center meta;
END;
$overpassUrl = "http://overpass-api.de/api/interpreter?data=".rawurlencode($stateQuery);

chdir(__DIR__);

// Overpass requires a valid User-Agent header.
$opts = [
  'http' => [
    'method' => "GET",
    // Use CRLF \r\n to separate multiple headers
    'header' => "User-Agent: Vermont Address Import https://wiki.openstreetmap.org/wiki/VCGI_E911_address_points_import",
  ]
];
$context = stream_context_create($opts);

$bytes = file_put_contents("osm_data/osm_addresses.osm", file_get_contents($overpassUrl, false, $context));
if ($bytes < 1) {
  fwrite(STDERR, "Failed to download from OverPass");
  exit(1);
}
if ($bytes < 61564437) {
  fwrite(STDERR, "Downloaded OSM data is less than half the size expected");
  exit(2);
}
