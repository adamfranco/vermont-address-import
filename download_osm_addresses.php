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
);
(._;);
out center;
END;
$overpassUrl = "http://overpass-api.de/api/interpreter?data=".rawurlencode($stateQuery);

chdir(__DIR__);
file_put_contents("osm_data/osm_addresses.osm", file_get_contents($overpassUrl));
