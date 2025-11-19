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
out center;
END;
$overpassUrl = "http://overpass-api.de/api/interpreter?data=".rawurlencode($stateQuery);

chdir(__DIR__);
file_put_contents("osm_data/osm_addresses.osm", file_get_contents($overpassUrl));
