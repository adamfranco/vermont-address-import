#!/usr/bin/env php
<?php

$print_errors_at_end = false;
// tab, osm, or geojson
// (note: when using tab outout, addresses that are missing a house number are outputted
// so they can be reviewed. They are not included in the osm output type.)
// geojson was quickly hacked on as a way of filtering out bad features from the original geojson file.
$output_type = "osm";

$help = "

". $argv[0] . " [-hv] [--help] [--verbose] [--output-type=osm|tab|geojson] <file.geojson>

  -h --help           Show this help
  -v --verbose        Print errors at the end.
  --output-type       Format of the output, default is osm.

  <file.geojson>      The input geojson file.

";

#options
$options = getopt("hv", ["help", "verbose", "output-type::"], $reset_index);
if ($options === FALSE || isset($options["h"]) || isset($options["help"])) {
  fwrite(STDERR, $help);
  exit(1);
}
if (isset($options["output-type"])) {
  if (!in_array($options["output-type"], ["osm", "tab", "geojson"])) {
    fwrite(STDERR, "Invalid output type: '".$options["output-type"]."'. Must be one of osm, tab, geojson.");
    fwrite(STDERR, $help);
    exit(2);
  }
  $output_type = $options["output-type"];
}
if (isset($options['v']) || isset($options['verbose'])) {
  $print_errors_at_end = true;
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

///////////////////////////////////////////////////////

$data = json_decode(file_get_contents($file), true);
if (is_null($data)) {
  fwrite(STDERR, "Failed decoding JSON from $file");
  fwrite(STDERR, $help);
  exit(4);
}

$node_id = -100;
$all_errors = array();

$output = output_header($output_type);

// Map our
$postalCommunities = json_decode(file_get_contents(__DIR__.'/town-postal-community-mappings.json'), true);

foreach($data['features'] as $feature) {
    $feature_errors = array();
    $non_fatal_feature_errors = array();

    if(!empty($feature['properties']['ESITEID'])) {
        $esiteid = $feature['properties']['ESITEID'];
    } else {
        $esiteid = NULL;
        $feature_errors[] = "ESITEID value is empty";
    }

    if(!empty($feature['geometry']['coordinates']['0'])) {
        $long = $feature['geometry']['coordinates']['0'];
    } else {
        $long = NULL;
        $feature_errors[] = "feature['geometry']['coordinates'][0] value is empty (esiteid: " . $esiteid . ")";
    }

    if(!empty($feature['geometry']['coordinates']['1'])) {
        $lat = $feature['geometry']['coordinates']['1'];
    } else {
        $lat = NULL;
        $feature_errors[] = "feature['geometry']['coordinates'][1] value is empty (esiteid: " . $esiteid . ")";
    }

    if(!empty($feature['properties']['Post_Comm'])) {
      $postal_community = $feature['properties']['Post_Comm'];
    } elseif (!empty($feature['properties']['Inc_Muni'])) {
        $townName = $feature['properties']['Inc_Muni'];
        if (!isset($postalCommunities[$townName])) {
          $postal_community = NULL;
          $feature_errors[] = "Unaccounted for mapping from Inc_Muni ".$townName." to a postal community in town-postal-community-mappings.json (esiteid: " . $esiteid . ")";
        } else {
          $postalCommunityMapping = $postalCommunities[$townName];
          if (empty($postalCommunityMapping['postal-community'])) {
            $postal_community = NULL;
          } else {
            $postal_community = $postalCommunityMapping['postal-community'];
          }
        }
    } else {
        $postal_community = NULL;
        $feature_errors[] = "Post_Comm & Inc_Muni values are empty (esiteid: " . $esiteid . ")";
    }

    // Most addresses will not use addr:unit, only ones with a numeric HOUSE_NUMBERSUFFIX.
    $unit = NULL;

    // confirm that the HOUSE_NUMBER is not empty, is a number greater than zero
    // VCGI contains lots of entries with a house number of "0"
    if(!empty($feature['properties']['Add_Number']) && is_numeric($feature['properties']['Add_Number']) && $feature['properties']['Add_Number'] > 0) {
        $house_number = $feature['properties']['Add_Number'];

        // check for prefix on house number (eg. esiteid 757868)
        if(!empty($feature['properties']['AddNum_Pre'])) {
            $prefix = trim($feature['properties']['AddNum_Pre']);
            // Don't use spaces to concatenate alpha-only prefixes.
            if (preg_match('/^[A-Z]+$/', $prefix)) {
                $house_number = $prefix . $house_number;
            }
            // If a non-alpha prefix is found, include a space to avoid merging
            // numbers.
            else {
                $house_number = $prefix . " " . $house_number;
            }
        }

        // check for suffix on house number (eg. esiteid 154277)
        if(!empty($feature['properties']['AddNum_Suf'])) {
            $suffix = trim($feature['properties']['AddNum_Suf']);
            // Don't use spaces to concatenate alpha-only suffix.
            if (preg_match('/^[A-Z]+$/i', $suffix)) {
                $house_number = $house_number . $suffix;
            }
            // Plain numbers should go in addr:unit
            elseif (preg_match('/^\d+$/', $suffix)) {
              $unit = $suffix;
            }
            // Unit ranges should go in addr:unit
            elseif (preg_match('/^(UNITS)?(\d+-\d+)$/', $suffix, $unit_matches)) {
              $unit = $unit_matches[2];
            }
            // Any other cases, like "1/2", concatenate with a space.
            else {
                $house_number = $house_number . " " . $suffix;
            }
        }

    } else {
        $house_number = NULL;
        $feature_errors[] = "Add_Number is invalid: " . json_encode($feature['properties']) . " (esiteid: " . $esiteid . ")";
    }

    if(!empty($feature['properties']['St_Name'])) {
        // Addresses on small islands often don't have any streets and are
        // only accessed by boat. Use an empty street and fill in place.
        if ($feature['properties']['St_Name'] == "IS") {
          $street = NULL;
          $place = build_street_name($feature['properties']);
        } else {
          $street = build_street_name($feature['properties']);
          $place = NULL;
        }
    } else {
        $street = NULL;
        $place = NULL;
        $feature_errors[] = "St_Name (street name) value is empty: (esiteid: " . $esiteid . ")";
    }

    if(!empty($feature['properties']['Post_Code'])) {
        $post_code = $feature['properties']['Post_Code'];
    } else {
        $post_code = NULL;
        $feature_errors[] = "Post_Code value is empty (esiteid: " . $esiteid . ")";
    }

    // Exceptions for Post Offices that have different postal communities than
    // the rest of their Town.

    // Beecher Falls Post Office in Canaan
    if ($esiteid == 764537 && $postal_community == 'Canaan') {
      $postal_community = 'Beecher Falls';
    }
    // East Middlebury Post office
    if ($esiteid == 156175 && $postal_community == 'Middlebury') {
      $postal_community = 'East Middlebury';
    }

    // Houses on Church Street in Waltham use Vergennes as the Postal community.
    if ($postal_community == 'Waltham' && $street == 'Church Street') {
      $postal_community = 'Vergennes';
    }

    // Houses on Maplewood Road in Montpelier use East Montpelier as the Postal community.
    if ($postal_community == 'Montpelier' && $street == 'Maplewood Road') {
      $postal_community = 'East Montpelier';
    }

    // "North Burlington" is not a valid postal community.
    if ($postal_community == 'North Burlington') {
      if (!preg_match("/ North Burlington( VT)?( [0-9]{5})?$/i", $feature['properties']['PrimaryAddress2'])) {
        $postal_community = 'Burlington';
      }
    }

    // "Univeristy of Vermont" is not a valid postal community.
    if (in_array($postal_community, ['University of Vermont', 'Univeristy of Vermont'])) {
      if (preg_match("/ ((South )?Burlington)( VT)?( [0-9]{5})?$/i", $feature['properties']['PrimaryAddress2'], $m)) {
        $postal_community = ucfirst(strtolower($m[1]));
      }
      else {
        $postal_community = ucfirst(strtolower($feature['properties']['Inc_Muni']));
      }
    }

    // "Saint Michales College" is not a valid postal community.
    if (in_array($postal_community, ['Saint Michaels College', 'Saint Michales College'])) {
      if (preg_match("/ (Colchester|Essex)( VT)?( [0-9]{5})?$/i", $feature['properties']['PrimaryAddress2'], $m)) {
        $postal_community = ucfirst(strtolower($m[1]));
      }
      else {
        $postal_community = ucfirst(strtolower($feature['properties']['Inc_Muni']));
      }
    }

    // Validate that the postal_community and post-code match
    // the PrimaryAddress2 field in the data.
    //
    // Some examples (like UVM campus addresses) had Post_Comm
    // set to something else ("University of Vermont") even though
    // the addresses are actually Burlington in PrimaryAddress2.
    if (!empty($postal_community) && !empty($post_code)) {
      $regex = "/ " . $postal_community . "( VT)?( " . $post_code . ')?$/i';
      if (!preg_match($regex, $feature['properties']['PrimaryAddress2'])) {
        $non_fatal_feature_errors[] = "Mismatch between PrimaryAddress2 and the discovered Post_Comm and Post_Code: (esiteid: " . $esiteid . ") Found \"" . $postal_community . " VT " . $post_code . "\" expected  \"" . $feature['properties']['PrimaryAddress2'] . '" ';
      }
    }

    // Annotate feature errors with the source.
    foreach ($feature_errors as $k => $v) {
      $feature_errors[$k] = $v . " " . json_encode($feature['properties']);
    }
    foreach ($non_fatal_feature_errors as $k => $v) {
      $non_fatal_feature_errors[$k] = $v . " " . json_encode($feature['properties']);
    }
    $all_errors[] = $feature_errors;
    $all_errors[] = $non_fatal_feature_errors;

    // if we don't have any errors in our data
    if(count($feature_errors) == 0 && $output_type == "osm") {

        // leaving out timestamp from node: timestamp='2022-09-12T01:50:00Z'
        $output .= "  <node id=\"" . $node_id . "\" visible=\"true\" lat=\"" . $lat . "\" lon=\"" . $long . "\">\n";
        if (!empty($postal_community)) {
          $output .= "    <tag k=\"addr:city\" v=\"" . $postal_community . "\" />\n";
        }
        $output .= "    <tag k=\"addr:housenumber\" v=\"" . $house_number . "\" />\n";
        if (!empty($unit)) {
            $output .= "    <tag k=\"addr:unit\" v=\"" . $unit . "\" />\n";
        }
        $output .= "    <tag k=\"addr:street\" v=\"" . $street . "\" />\n";
        // Addresses on small islands often don't have any streets and are
        // only accessed by boat. Use an empty street and fill in place.
        if (!empty($place)) {
            $output .= "    <tag k=\"addr:place\" v=\"" . $place . "\" />\n";
        }
        if (!empty($post_code)) {
          $output .= "    <tag k=\"addr:postcode\" v=\"" . $post_code . "\" />\n";
        }
        $output .= "    <tag k=\"addr:state\" v=\"VT\" />\n";
        $output .= "    <tag k=\"ref:vcgi:esiteid\" v=\"" . $esiteid . "\" />\n";
        // use this tag in the changeset tags instead of node tag
        // $output .= "    <tag k=\"source\" v=\"VCGI/E911_address_points\" />\n";
        $output .= "  </node>\n";


    } elseif($output_type == "tab") {
        $output .= $node_id . "\t" . $lat . "\t" . $long . "\t";
        if (!empty($postal_community)) {
          $output .= $postal_community . "\t";
        }
        $output .= $house_number . "\t";
        $output .= $unit . "\t";
        $output .= $street . "\t";
        $output .= $post_code . "\t";
        $output .= $esiteid . "\n";

    } elseif(count($feature_errors) == 0 && $output_type == "geojson") {

        $coordinates = array($long, $lat);
        $properties = [
          "house_number" => strval($house_number),
          "unit" => strval($unit),
          "street" => $street,
        ];
        if (!empty($postal_community)) {
          $properties["city"] = $postal_community;
        }
        $properties["state"] = "VT";
        if (!empty($post_code)) {
          $properties["postcode"] = $post_code;
        }
        $properties["esiteid"] = strval($esiteid);
        $geometry = array("type" => "Point", "coordinates" => $coordinates);
        $feature = array("type" => "Feature", "properties" => $properties, "geometry" => $geometry);


        // todo: geojson output is a hack
        // this adds an extraneous comma to the last feature that needs to be removed
        // but not sure it is worth reworking
        $output .= json_encode($feature) . ",\n";

    }
    $node_id--;
    unset($feature_errors);
}

$output .= output_footer($output_type);
print $output;

if($print_errors_at_end) {
    fwrite(STDERR, "\n----------ERRORS----------\n");
    if(count($all_errors) > 0) {
        $i = 1;
        foreach($all_errors as $feature_errors) {
            foreach($feature_errors as $error_item) {
                fwrite(STDERR, $i . " " . $error_item . "\n");
                $i++;
            }

        }
    } else {
        fwrite(STDERR, "no errors\n");
    }
}

///////////////////////////////////////////
//
// Functions
//
///////////////////////////////////////////


function output_header($output_type) {
    if($output_type == "osm") {
        $header = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<osm version=\"0.6\" generator=\"JOSM\" upload=\"false\">\n";
    } elseif($output_type == "geojson") {
        $header = "{\"type\": \"FeatureCollection\", \"features\": [\n";
    } else {
        $header = "";
    }

    return $header;
}

function output_footer($output_type) {
    if($output_type == "osm") {
        $footer = "</osm>\n";
    } elseif($output_type == "geojson") {
        $footer = "]}\n";
    } else {
        $footer = "";
    }

    return $footer;
}

function build_street_name($feature_properties) {

    $streetNameParts = [];

    // St_PreMod:  Street Name Pre Modifier. Precedes and is separated from street name by a pre type and/or pre direction. The "Old" in "Old North Church Street", for example.
    if(!empty($feature_properties['St_PreMod'])) {
        $value = trim($feature_properties['St_PreMod']);
        if (!empty($value)) {
          $streetNameParts[] = $value;
        }
    }

    // St_PreDir: Street Name Pre Directional. North, South, East, West, Northeast, etc.
    if (!empty($feature_properties['St_PreDir'])) {
        $value = trim($feature_properties['St_PreDir']);
        if (!empty($value)) {
            $streetNameParts[] = $value;
        }
    }

    // St_PreTyp: Street Name Pre Type. The "Avenue" in "Avenue A", for example.
    if (!empty($feature_properties['St_PreTyp'])) {
        $value = trim($feature_properties['St_PreTyp']);
        if (!empty($value)) {
            if ($value == "United States Route") {
              $streetNameParts[] = "U.S. Route";
            } else {
              $streetNameParts[] = $value;
            }
        }
    }

    // St_PreSep: Street Name Pre Type Separator. The "of the" in "Avenue of the Stars", for example.
    if (!empty($feature_properties['St_PreSep'])) {
        $value = trim($feature_properties['St_PreSep']);
        if (!empty($value)) {
            $streetNameParts[] = $value;
        }
    }

    // St_Name:  Street Name.
    if (!empty($feature_properties['St_Name'])) {
        $value = trim($feature_properties['St_Name']);
        // Case where St_NAME is just 110 and St_PreTyp is missing.
        // Get the name from PRIMARYNAME.
        if (preg_match('/^\d+$/', $value) && preg_match('/^VT ROUTE \d+/', $feature_properties['PRIMARYNAME']) && empty($feature_properties['St_PreTyp'])) {
          $value = "Vermont Route " . $value;
        }

        $value = normalize_street_base_name($value, $feature_properties['St_PosTyp'], $feature_properties['Inc_Muni']);
        if (!empty($value)) {
            $streetNameParts[] = $value;
        }
    }

    // St_PosTyp:  Street Name Post Type.
    if (!empty($feature_properties['St_PosTyp'])) {
        $value = trim($feature_properties['St_PosTyp']);
        if (!empty($value)) {
            $streetNameParts[] = $value;
        }
    }

    // St_PosDir:  Street Name Post Directional. North, South, East, West, Northeast, etc.
    if (!empty($feature_properties['St_PosDir'])) {
        $value = trim($feature_properties['St_PosDir']);
        if (!empty($value)) {
            $streetNameParts[] = $value;
        }
    }

    // St_PosMod:  Street Name Post Modifier. Follows and is separated from street name by a post type and/or post direction. "Extension" in "Main Street Extension", for example.
    if (!empty($feature_properties['St_PosMod'])) {
        $value = trim($feature_properties['St_PosMod']);
        if (!empty($value)) {
            $streetNameParts[] = $value;
        }
    }

    return trim(implode(" ", $streetNameParts));
}

function normalize_street_base_name($street_name, $street_suffix, $town_name) {

    // todo: deal with street names with apostrophes (eg. O'Neil)

    $street_name_title_cased = $street_name;

    // If street name starts with "Vt " replace with "Vermont "
    if (preg_match('/^Vt (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "Vermont " . $matches[1];

        // Make sure any trailing letters like in "22A" are capitalized.
        if (preg_match('/^(.+\d+)([a-z]+)$/i', $street_name_title_cased, $matches)) {
            $street_name_title_cased = $matches[1].strtoupper($matches[2]);
        }
    }

    // If street name starts with Mc, fix it so next letter is also uppercase.
    // todo: might be exceptions to this rule
    if (strpos($street_name_title_cased, 'Mc') === 0) {
        $street_name_title_cased = 'Mc' . ucwords(substr($street_name_title_cased, 2, strlen($street_name_title_cased)));
    }

    // OSM shows "U.S. Route #" where was e911 has US Route 5
    if(preg_match('/us route (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "U.S. Route " . $matches[1];
    }
    // OSM shows "U.S. Route #" where new e911 has United States Route 5
    if(preg_match('/united states route (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "U.S. Route " . $matches[1];
    }

    // VCGI data uses "NFR", which should be expanded to "National Forest Road"
    if(preg_match('/nfr (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "National Forest Road " . $matches[1];
    }

    // expand when hwy is in the middle of the street name (eg. Town Hwy 11)
    // originally found in Granville
    if(preg_match('/town hwy (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "Town Highway " . $matches[1];
    }

    // expand Col => Colonel.
    // Found in Shelburne, Charlotte, and Rochester
    if(preg_match('/^Col (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "Colonel " . $matches[1];
    }

    // Expand Mhp => "Mobile Home Park at the end of the street name.
    // Bennington has addresses with this pattern such as esiteid 16062.
    if(preg_match('/(.+) Mhp$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . " Mobile Home Park";
    }

    // Expand Rd => and directionals at the end of the street name.
    // Landgrove has roads like "Old County RD E", esiteid 139240.
    if(preg_match('/(.+) Rd E$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . " Road East";
    }
    if(preg_match('/(.+) Rd W$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . " Road East";
    }
    if(preg_match('/(.+) Rd N$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . " Road North";
    }
    if(preg_match('/(.+) Rd S$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . " Road South";
    }

    // expand when Rd is in the middle of the street name (eg. Private Rd 11, Old Rd Nine)
    // originally found in Vershire, esiteid 266922 and more.
    if(preg_match('/^(.+) Rd (.+)$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . " Road " . $matches[2];
    }

    // Pittsfield has a road called "South Hill Road Pittsfield", with the
    // end abbreviated to "Ptfld". esiteid 764374
    if(preg_match('/^(.+) Ptfld$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . " Pittsfield";
    }

    // Hubbardton has a street called LHCS that needs to be all caps
    if(preg_match('/^lhcs(.*)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "LHCS" . $matches[1];
    }

    // Hubbardton has a street called "SFH"... not sure what it stands for (State Forest ?), but capitlizing it
    if(preg_match('/^sfh/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "SFH";
    }

    // Colchester has a road called "EOC".
    if ($street_name_title_cased == "Eoc") {
        $street_name_title_cased = "EOC";
    }

    // Middlebury has a street name HMKL that should be capitalized. (esiteid: 155140)
    if(preg_match('/^hmkl/i', $street_name_title_cased)) {
        $street_name_title_cased = "HMKL";
    }

    // Williston has a road named IBM Road that should be capitalized. (esiteid: 288614)
    if(preg_match('/^Ibm/i', $street_name_title_cased)) {
        $street_name_title_cased = "IBM";
    }

    // Brookfield has a street with "EXT" in the ST (street type) field, which causes
    // Rd and Ln to be put at the end of the SN (street name) field, so we need to expand the street name abbreviation as well
    if(preg_match('/(.+) (Ave|Dr|Ln|Rd|St|Cir)$/i', $street_name_title_cased, $matches)) {
        $expanded_suffix = expand_street_name_suffix($matches[2]);
        $street_name_title_cased = $matches[1] . " " . $expanded_suffix;
    }

    // Brookfield has a street that starts with "Dr".  Expand to "Doctor"
    if(preg_match('/^Dr (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "Doctor " . $matches[1];
    }

    // Warren has a street name that includes Ctr abbreviated in the middle of
    // the name "Sports Ctr Drive". (esiteid: 271477, 271493)
    if(preg_match('/^(.+) Ctr( .*)?$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . " Center";
        if (!empty($matches[2])) {
            $street_name_title_cased .= $matches[2];
        }
    }

    // Danville has a street name that includes Meml abbreviated in the middle of
    // the name "Bruce Badger Memorial Highway". (esiteid: 80451)
    if (preg_match('/^(.+) Meml( .*)?$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . " Memorial";
        if (!empty($matches[2])) {
            $street_name_title_cased .= $matches[2];
        }
    }

    // West Haven has an "Old Route 22A" which is mis-capitalized as
    // "Old Route 22a" with ucwords(). (esiteid: 279120)
    if(preg_match('/^(Old Route \d+)([a-z])$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . strtoupper($matches[2]);
    }

    // Monkton has a street named "A B C D" that exists in E911 as "ABCD".
    if ($street_name_title_cased == "Abcd") {
        $street_name_title_cased = "A B C D";
    }

    // Northfield has a street named "I.D. White" that exists in E911 as "ID WHITE".
    if ($street_name_title_cased == "Id White") {
        $street_name_title_cased = "I.D. White";
    }

    // East Montpelier has a street named "Tay-Con" that exists in E911 as "TAY CON".
    if ($street_name_title_cased == "Tay Con") {
        $street_name_title_cased = "Tay-Con";
    }

    // Avery's Gore has a street named "Mark's" that exists in E911 as "Marks".
    if ($street_name_title_cased == "Marks") {
        $street_name_title_cased = "Mark's";
    }

    // East Haven has a Boulevard named "George's" that exists in E911 as "Georges".
    if ($street_name_title_cased == "Georges") {
        $street_name_title_cased = "George's";
    }

    // East Haven has a Road named "Young's" that exists in E911 as "Youngs".
    if ($street_name_title_cased == "Youngs") {
        $street_name_title_cased = "Young's";
    }

    // Greensboro has a Road named "Maggie's Pond" that is missing the possessive.
    // (esiteid: 115185)
    if ($street_name_title_cased == "Maggies Pond") {
        $street_name_title_cased = "Maggie's Pond";
    }

    if ($street_name_title_cased == "O Gorman" || $street_name_title_cased == "Ogorman") {
        $street_name_title_cased = "O'Gorman";
    }

    // Craftsbury has a Road name "Pete's Greens" that is missing the possesive.
    // (esiteid: 79318)
    if ($street_name_title_cased == "Petes Greens") {
        $street_name_title_cased = "Pete's Greens";
    }

    // West Fairlee has a Way name "Grandkid's" that is missing the possesive.
    // (esiteid: 764338)
    if ($street_name_title_cased == "Grandkids") {
        $street_name_title_cased = "Grandkid's";
    }

    // Brighton has a Road named "RLW" that gets UC-first.
    if ($street_name_title_cased == "Rlw") {
        $street_name_title_cased = "RLW";
    }

    // Hinesburg has a Road named "CVU" that gets UC-first.
    if ($street_name_title_cased == "Cvu") {
        $street_name_title_cased = "CVU";
    }

    // Brighton has a Road named "Head of the Pond Road" that gets UC-first.
    if(preg_match('/^(.*) Of The (.*)$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . " of the " . $matches[2];
    }

    // Peacham has a Road named "Bayley-Hazen" that is missing the dash in E911.
    if(preg_match('/^(.*)Bayley Hazen(.*)$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = $matches[1] . "Bayley-Hazen" . $matches[2];
    }

    // Cambridge has a Drive called "G W Tatro".
    // (esiteid: 57487)
    if ($street_name_title_cased == "Gw Tatro") {
        $street_name_title_cased = "G W Tatro";
    }

    // Westmore has a Road called "Old 5A".
    // (esiteid: 284370)
    if ($street_name_title_cased == "Old 5a") {
        $street_name_title_cased = "Old 5A";
    }

    // Morgan has a Road called "I P".
    // (esiteid: 168568)
    if ($street_name_title_cased == "Ip") {
        $street_name_title_cased = "I P";
    }

    // Orange has a Drive called "BLS".
    // (esiteid: 183728)
    if ($street_name_title_cased == "Bls") {
        $street_name_title_cased = "BLS";
    }

    // Thetford has a Drive called "REB Mountain".
    // (esiteid: 259036)
    if ($street_name_title_cased == "Reb Mountain") {
        $street_name_title_cased = "REB Mountain";
    }

    // Thetford has a Way called "Malaka's".
    // (esiteid: 259025)
    if ($street_name_title_cased == "Makalas") {
        $street_name_title_cased = "Makala's";
    }

    // Hubbardton has a Way called "Jason's".
    // (esiteid: 130797)
    if ($street_name_title_cased == "Jasons") {
        $street_name_title_cased = "Jason's";
    }

    // South Burlington has a road called "Ally's Run".
    // (esiteid: 613149)
    if ($street_name_title_cased == "Allys") {
        $street_name_title_cased = "Ally's";
    }

    // Isle La Motte has a Lane called "Saint Joseph's".
    // (esiteid: 134211)
    if ($street_name_title_cased == "Saint Josephs") {
        $street_name_title_cased = "Saint Joseph's";
    }

    // Poultney has a road called "On the Green".
    // (esiteid: 191139)
    if ($street_name_title_cased == "On The Green") {
        $street_name_title_cased = "On the Green";
    }

    // Wells, Pawlett, and Tinmouth have a road called "Vermont Route 133 West Tinmouth".
    // (esiteid: 278320)
    if ($street_name_title_cased == "Vermont Route 133 W Tinmouth") {
        $street_name_title_cased = "Vermont Route 133 West Tinmouth";
    }

    // Clarendon has a road called "Vermont Route 7B Central".
    // (esiteid: 68815)
    if ($street_name_title_cased == "Vermont Route 7b Central") {
        $street_name_title_cased = "Vermont Route 7B Central";
    }

    // Clarendon has a road called "Vermont Route 7B North Extension".
    // (esiteid: 68218)
    if ($street_name_title_cased == "Vermont Route 7b N") {
        $street_name_title_cased = "Vermont Route 7B North";
    }

    // Shrewsbury has a road called "CCC".
    // (esiteid: 226776)
    if ($street_name_title_cased == "Ccc") {
        $street_name_title_cased = "CCC";
    }

    // Royalton has a Lane called "LDS".
    // (esiteid: 206170)
    if ($street_name_title_cased == "Lds") {
        $street_name_title_cased = "LDS";
    }

    // Plymouth has a Road called "PCN".
    // (esiteid: 190113)
    if ($street_name_title_cased == "Pcn") {
        $street_name_title_cased = "PCN";
    }

    // South Burlington has a Drive called "NCO".
    // (esiteid: 449122)
    if ($street_name_title_cased == "Nco") {
        $street_name_title_cased = "NCO";
    }

    // Alburgh has a road called "Point of Tongue".
    // (esiteid: 454372)
    if ($street_name_title_cased == "Point Of Tongue") {
        $street_name_title_cased = "Point of Tongue";
    }

    // Alburgh has a road called "Truck Route".
    // (esiteid: 2042)
    if ($street_name_title_cased == "Truck") {
        $street_name_title_cased = "Truck";
    }

    // Hartford has a road called "V.A. Cutoff Road".
    // (esiteid: 122757)
    if ($street_name_title_cased == "Va Cutoff") {
        $street_name_title_cased = "V.A. Cutoff";
    }

    // Hartford has a road called "Center of Town Road".
    // (esiteid: 122112)
    if ($street_name_title_cased == "Center Of Town") {
        $street_name_title_cased = "Center of Town";
    }

    // Hartford has a road called "O'Connell Court".
    // (esiteid: 121685)
    if ($street_name_title_cased == "Oconnell") {
        $street_name_title_cased = "O'Connell";
    }

    // South Burlington has a road called "O'Brien Farm Road".
    // (esiteid: 616901)
    if(preg_match('/^Obrien(.*)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "O'Brien" . $matches[1];
    }

    // Fairlee has a road called "O'Donnell Drive".
    if(preg_match('/^Odonnell(.*)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "O'Donnell" . $matches[1];
    }

    // Vernon has a road called "O'Neil Drive".
    if(preg_match('/^Oneil(.*)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "O'Neil" . $matches[1];
    }

    // Putney has a road called "Hi-Lo Biddy".
    // (esiteid: 612012)
    if ($street_name_title_cased == "Hi Lo Biddy") {
        $street_name_title_cased = "Hi-Lo Biddy";
    }

    // Saint George has a road called "Whitetail Path" that is incorrectly in E911
    // as "White Tail Path". (esiteid: 765361, 765363, 765364, 765365)
    // The "White Tail" roads in other towns seem to mostly be two-words on the signs.
    if ($street_name_title_cased == "White Tail" && $street_suffix == 'Path' && strtoupper($town_name) == 'SAINT GEORGE') {
        $street_name_title_cased = "Whitetail";
    }

    // Winooski & Burlington both have streets called "LAFOUNTAIN STREET", and Weybridge
    // has a "LAFOUNTAIN LN". The Burlington one is signed as "Lafountain St",
    // the Weybridge one as "LAFOUNTAIN LN", whereas the Winooski one is signed
    // "LaFountain St" and Winooski city documents refer to it as "Lafountain Street"
    // or "LaFountain Street" in most documents and "La Fountain" in just a few cases.
    //
    // For the Winooski case, "LaFountain" is probably a good compromise as it
    // matches "Lafountain" in case-insensitive searches while preserving the
    // distinction on the street signs.
    if ($street_name_title_cased == "Lafountain" && $street_suffix == 'Street' && strtoupper($town_name) == 'WINOOSKI') {
        $street_name_title_cased = "LaFountain";
    }

    // The Winooski street, "LaPointe St" is signed with an upper case P and no space.
    // Winooski city documents refer to it as "LaPointe Street" or "LaPointe Street"
    // but not "La Pointe Street" in documents on the city website.
    if ($street_name_title_cased == "Lapointe" && $street_suffix == 'Street' && strtoupper($town_name) == 'WINOOSKI') {
        $street_name_title_cased = "LaPointe";
    }

    // Milton has a road signed "La Casse Drive"
    if ($street_name_title_cased == "Lacasse") {
        $street_name_title_cased = "La Casse";
    }

    // Fairfield has 3 roads like "Napoli Road 1", "Napoli Road 2", and "Napoli Road 3" in E911.
    // The first first should probably be "Napoli Camp Road" and the later two "Napoli Road".
    // (esiteid: 104494, 104507, 104520)
    if (preg_match('/^Napoli Road 1$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "Napoli Camp Road";
    }
    if (preg_match('/^Napoli Road (2|3)$/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "Napoli Road";
    }

    // West Windsor has a road called "Brownsville-Hartland Road.
    // (esiteid: 280539)
    if ($street_name_title_cased == "Brownsville Hartland") {
        $street_name_title_cased = "Brownsville-Hartland";
    }

    // Westminster has a road called "Lewis Path" that has the suffix in the primary name field.
    // (esiteid: 282950)
    if ($street_name_title_cased == "Lewis Pa") {
        $street_name_title_cased = "Lewis Path";
    }

    // Andover has a road called "Willie's Lane".
    // (esiteid: 3415)
    if ($street_name_title_cased == "Willies") {
        $street_name_title_cased = "Willie's";
    }

    // Vernon has a road called "T-Bird Drive" and Lyndon has a "T-Bird Lane".
    // (esiteid: 265978)
    if ($street_name_title_cased == "T Bird") {
        $street_name_title_cased = "T-Bird";
    }

    if ($street_name_title_cased == "Nameless Ytbd") {
        $street_name_title_cased = "Nameless (Yet to be Determined)";
    }

    return $street_name_title_cased;
}

// street name suffix (eg. Avenue) are abbreviated in VCGI data
function expand_street_name_suffix($street_name_suffix) {

    $street_name_suffix = strtolower(trim($street_name_suffix));

    // list from https://github.com/blackboxlogic/OsmTagsTranslator/blob/master/OsmTagsTranslator/Lookups/StreetSuffixes.json
    $street_suffixes = array("allee" => "Alley",
                            "alley" => "Alley",
                            "ally" => "Alley",
                            "aly" => "Alley",
                            "anex" => "Anex",
                            "annex" => "Anex",
                            "annx" => "Anex",
                            "anx" => "Anex",
                            "arc" => "Arcade",
                            "arcade" => "Arcade",
                            "av" => "Avenue",
                            "ave" => "Avenue",
                            "aven" => "Avenue",
                            "avenu" => "Avenue",
                            "avenue" => "Avenue",
                            "avn" => "Avenue",
                            "avnue" => "Avenue",
                            "bayoo" => "Bayou",
                            "bayou" => "Bayou",
                            "bch" => "Beach",
                            "beach" => "Beach",
                            "bend" => "Bend",
                            "blf" => "Bluff",
                            "blfs" => "Bluffs",
                            "bluf" => "Bluff",
                            "bluff" => "Bluff",
                            "bluffs" => "Bluffs",
                            "blvd" => "Boulevard",
                            "bnd" => "Bend",
                            "bot" => "Bottom",
                            "bottm" => "Bottom",
                            "bottom" => "Bottom",
                            "boul" => "Boulevard",
                            "boulevard" => "Boulevard",
                            "boulv" => "Boulevard",
                            "br" => "Branch",
                            "branch" => "Branch",
                            "brdge" => "Bridge",
                            "brg" => "Bridge",
                            "bridge" => "Bridge",
                            "brk" => "Brook",
                            "brnch" => "Branch",
                            "brook" => "Brook",
                            "brooks" => "Brooks",
                            "btm" => "Bottom",
                            "burg" => "Burg",
                            "burgs" => "Burgs",
                            "byp" => "Bypass",
                            "bypa" => "Bypass",
                            "bypas" => "Bypass",
                            "bypass" => "Bypass",
                            "byps" => "Bypass",
                            "camp" => "Camp",
                            "canyn" => "Canyon",
                            "canyon" => "Canyon",
                            "cape" => "Cape",
                            "causeway" => "Causeway",
                            "causwa" => "Causeway",
                            "cen" => "Center",
                            "cent" => "Center",
                            "center" => "Center",
                            "centers" => "Centers",
                            "centr" => "Center",
                            "centre" => "Center",
                            "cir" => "Circle",
                            "circ" => "Circle",
                            "cirs" => "Circles",
                            "circl" => "Circle",
                            "circle" => "Circle",
                            "circles" => "Circles",
                            "clb" => "Club",
                            "clf" => "Cliff",
                            "clfs" => "Cliffs",
                            "cliff" => "Cliff",
                            "cliffs" => "Cliffs",
                            "club" => "Club",
                            "cmn" => "Common",
                            "cmns" => "Commons",
                            "cmp" => "Camp",
                            "cnter" => "Center",
                            "cntr" => "Center",
                            "cnyn" => "Canyon",
                            "common" => "Common",
                            "commons" => "Commons",
                            "cor" => "Corner",
                            "corner" => "Corner",
                            "corners" => "Corners",
                            "cors" => "Corners",
                            "course" => "Course",
                            "court" => "Court",
                            "courts" => "Courts",
                            "cove" => "Cove",
                            "coves" => "Coves",
                            "cp" => "Camp",
                            "cpe" => "Cape",
                            "crcl" => "Circle",
                            "crcle" => "Circle",
                            "creek" => "Creek",
                            "cres" => "Crescent",
                            "crescent" => "Crescent",
                            "crest" => "Crest",
                            "crk" => "Creek",
                            "crossing" => "Crossing",
                            "crossroad" => "Crossroad",
                            "crossroads" => "Crossroads",
                            "crse" => "Course",
                            "crsent" => "Crescent",
                            "crsnt" => "Crescent",
                            "crssng" => "Crossing",
                            "crst" => "Crest",
                            "cswy" => "Causeway",
                            "ct" => "Court",
                            "ctr" => "Center",
                            "cts" => "Courts",
                            "curv" => "Curve",
                            "curve" => "Curve",
                            "cv" => "Cove",
                            "dale" => "Dale",
                            "dam" => "Dam",
                            "div" => "Divide",
                            "divide" => "Divide",
                            "dl" => "Dale",
                            "dm" => "Dam",
                            "dr" => "Drive",
                            "driv" => "Drive",
                            "drive" => "Drive",
                            "drives" => "Drives",
                            "drv" => "Drive",
                            "dv" => "Divide",
                            "dvd" => "Divide",
                            "est" => "Estate",
                            "estate" => "Estate",
                            "estates" => "Estates",
                            "ests" => "Estates",
                            "exp" => "Expressway",
                            "expr" => "Expressway",
                            "express" => "Expressway",
                            "expressway" => "Expressway",
                            "expw" => "Expressway",
                            "expy" => "Expressway",
                            "ext" => "Extension",
                            "extension" => "Extension",
                            "extn" => "Extension",
                            "extnsn" => "Extension",
                            "exts" => "Extensions",
                            "fall" => "Fall",
                            "falls" => "Falls",
                            "ferry" => "Ferry",
                            "field" => "Field",
                            "fields" => "Fields",
                            "flat" => "Flat",
                            "flats" => "Flats",
                            "fld" => "Field",
                            "flds" => "Fields",
                            "fls" => "Falls",
                            "flt" => "Flat",
                            "flts" => "Flats",
                            "ford" => "Ford",
                            "fords" => "Fords",
                            "forest" => "Forest",
                            "forests" => "Forest",
                            "forg" => "Forge",
                            "forge" => "Forge",
                            "forges" => "Forges",
                            "fork" => "Fork",
                            "forks" => "Forks",
                            "fort" => "Fort",
                            "frd" => "Ford",
                            "freeway" => "Freeway",
                            "freewy" => "Freeway",
                            "frg" => "Forge",
                            "frk" => "Fork",
                            "frks" => "Forks",
                            "frry" => "Ferry",
                            "frst" => "Forest",
                            "frt" => "Fort",
                            "frway" => "Freeway",
                            "frwy" => "Freeway",
                            "fry" => "Ferry",
                            "ft" => "Fort",
                            "fwy" => "Freeway",
                            "garden" => "Garden",
                            "gardens" => "Gardens",
                            "gardn" => "Garden",
                            "gateway" => "Gateway",
                            "gatewy" => "Gateway",
                            "gatway" => "Gateway",
                            "gdn" => "Garden",
                            "gdns" => "Gardens",
                            "glen" => "Glen",
                            "glens" => "Glens",
                            "gln" => "Glen",
                            "grden" => "Garden",
                            "grdn" => "Garden",
                            "grdns" => "Gardens",
                            "green" => "Green",
                            "greens" => "Greens",
                            "grn" => "Green",
                            "grov" => "Grove",
                            "grove" => "Grove",
                            "groves" => "Groves",
                            "grv" => "Grove",
                            "gtway" => "Gateway",
                            "gtwy" => "Gateway",
                            "harb" => "Harbor",
                            "harbor" => "Harbor",
                            "harbors" => "Harbors",
                            "harbr" => "Harbor",
                            "haven" => "Haven",
                            "hbr" => "Harbor",
                            "highway" => "Highway",
                            "highwy" => "Highway",
                            "hill" => "Hill",
                            "hills" => "Hills",
                            "hiway" => "Highway",
                            "hiwy" => "Highway",
                            "hl" => "Hill",
                            "hllw" => "Hollow",
                            "hls" => "Hills",
                            "hollow" => "Hollow",
                            "hollows" => "Hollow",
                            "holw" => "Hollow",
                            "holws" => "Hollow",
                            "hrbor" => "Harbor",
                            "ht" => "Heights",
                            "hts" => "Heights",
                            "hvn" => "Haven",
                            "hway" => "Highway",
                            "hwy" => "Highway",
                            "inlt" => "Inlet",
                            "is" => "Island",
                            "island" => "Island",
                            "islands" => "Islands",
                            "isle" => "Isle",
                            "isles" => "Isle",
                            "islnd" => "Island",
                            "islnds" => "Islands",
                            "iss" => "Islands",
                            "jct" => "Junction",
                            "jction" => "Junction",
                            "jctn" => "Junction",
                            "jctns" => "Junctions",
                            "jcts" => "Junctions",
                            "junction" => "Junction",
                            "junctions" => "Junctions",
                            "junctn" => "Junction",
                            "juncton" => "Junction",
                            "key" => "Key",
                            "keys" => "Keys",
                            "knl" => "Knoll",
                            "knls" => "Knolls",
                            "knol" => "Knoll",
                            "knoll" => "Knoll",
                            "knolls" => "Knolls",
                            "ky" => "Key",
                            "kys" => "Keys",
                            "lake" => "Lake",
                            "lakes" => "Lakes",
                            "land" => "Land",
                            "landing" => "Landing",
                            "lane" => "Lane",
                            "lck" => "Lock",
                            "lcks" => "Locks",
                            "ldg" => "Lodge",
                            "ldge" => "Lodge",
                            "lf" => "Loaf",
                            "lgt" => "Light",
                            "lgts" => "Lights",
                            "light" => "Light",
                            "lights" => "Lights",
                            "lk" => "Lake",
                            "lks" => "Lakes",
                            "ln" => "Lane",
                            "lndg" => "Landing",
                            "lndng" => "Landing",
                            "loaf" => "Loaf",
                            "lock" => "Lock",
                            "locks" => "Locks",
                            "lodg" => "Lodge",
                            "lodge" => "Lodge",
                            "loop" => "Loop",
                            "loops" => "Loop",
                            "mall" => "Mall",
                            "manor" => "Manor",
                            "manors" => "Manors",
                            "mdw" => "Meadows",
                            "mdws" => "Meadows",
                            "meadow" => "Meadow",
                            "meadows" => "Meadows",
                            "medows" => "Meadows",
                            "mews" => "Mews",
                            "mill" => "Mill",
                            "mills" => "Mills",
                            "missn" => "Mission",
                            "ml" => "Mill",
                            "mnr" => "Manor",
                            "mnrs" => "Manors",
                            "mnt" => "Mount",
                            "mntain" => "Mountain",
                            "mntn" => "Mountain",
                            "mntns" => "Mountains",
                            "motorway" => "Motorway",
                            "mount" => "Mount",
                            "mountain" => "Mountain",
                            "mountains" => "Mountains",
                            "mountin" => "Mountain",
                            "mssn" => "Mission",
                            "mt" => "Mount",
                            "mtin" => "Mountain",
                            "mtn" => "Mountain",
                            "nck" => "Neck",
                            "neck" => "Neck",
                            "orch" => "Orchard",
                            "orchard" => "Orchard",
                            "orchrd" => "Orchard",
                            "oval" => "Oval",
                            "overpass" => "Overpass",
                            "ovl" => "Oval",
                            "pa" => "Path",
                            "park" => "Park",
                            "parks" => "Parks",
                            "parkway" => "Parkway",
                            "parkways" => "Parkways",
                            "parkwy" => "Parkway",
                            "pass" => "Pass",
                            "passage" => "Passage",
                            "path" => "Path",
                            "paths" => "Path",
                            "pd" => "Pond",
                            "pike" => "Pike",
                            "pikes" => "Pike",
                            "pine" => "Pine",
                            "pines" => "Pines",
                            "pkway" => "Parkway",
                            "pkwy" => "Parkway",
                            "pkwys" => "Parkways",
                            "pky" => "Parkway",
                            "pl" => "Place",
                            "plain" => "Plain",
                            "plains" => "Plains",
                            "plaza" => "Plaza",
                            "pln" => "Plain",
                            "plns" => "Plains",
                            "plz" => "Plaza",
                            "plza" => "Plaza",
                            "pne" => "Pine",
                            "pnes" => "Pines",
                            "point" => "Point",
                            "points" => "Points",
                            "port" => "Port",
                            "ports" => "Ports",
                            "pr" => "Prairie",
                            "prairie" => "Prairie",
                            "prk" => "Park",
                            "prr" => "Prairie",
                            "prt" => "Port",
                            "prts" => "Ports",
                            "psge" => "Passage",
                            "pt" => "Point",
                            "pts" => "Points",
                            "rad" => "Radial",
                            "radial" => "Radial",
                            "radiel" => "Radial",
                            "radl" => "Radial",
                            "ramp" => "Ramp",
                            "ranch" => "Ranch",
                            "ranches" => "Ranch",
                            "rapid" => "Rapid",
                            "rapids" => "Rapids",
                            "rd" => "Road",
                            "rdg" => "Ridge",
                            "rdge" => "Ridge",
                            "rdgs" => "Ridges",
                            "rds" => "Roads",
                            "rest" => "Rest",
                            "ridge" => "Ridge",
                            "ridges" => "Ridges",
                            "riv" => "River",
                            "river" => "River",
                            "rivr" => "River",
                            "rnch" => "Ranch",
                            "rnchs" => "Ranch",
                            "road" => "Road",
                            "roads" => "Roads",
                            "route" => "Route",
                            "row" => "Row",
                            "rpd" => "Rapid",
                            "rpds" => "Rapids",
                            "rst" => "Rest",
                            "rte" => "Route",
                            "rue" => "Rue",
                            "run" => "Run",
                            "rvr" => "River",
                            "shl" => "Shoal",
                            "shls" => "Shoals",
                            "shoal" => "Shoal",
                            "shoals" => "Shoals",
                            "shoar" => "Shore",
                            "shoars" => "Shores",
                            "shore" => "Shore",
                            "shores" => "Shores",
                            "shr" => "Shore",
                            "shrs" => "Shores",
                            "skyway" => "Skyway",
                            "smt" => "Summit",
                            "spg" => "Spring",
                            "spgs" => "Springs",
                            "spng" => "Spring",
                            "spngs" => "Springs",
                            "spring" => "Spring",
                            "springs" => "Springs",
                            "sprng" => "Spring",
                            "sprngs" => "Springs",
                            "spur" => "Spur",
                            "spurs" => "Spurs",
                            "sq" => "Square",
                            "sqr" => "Square",
                            "sqre" => "Square",
                            "sqrs" => "Squares",
                            "squ" => "Square",
                            "square" => "Square",
                            "squares" => "Squares",
                            "st" => "Street",
                            "sta" => "Station",
                            "station" => "Station",
                            "statn" => "Station",
                            "stn" => "Station",
                            "str" => "Street",
                            "stra" => "Stravenue",
                            "strav" => "Stravenue",
                            "straven" => "Stravenue",
                            "stravenue" => "Stravenue",
                            "stravn" => "Stravenue",
                            "stream" => "Stream",
                            "street" => "Street",
                            "streets" => "Streets",
                            "streme" => "Stream",
                            "strm" => "Stream",
                            "strt" => "Street",
                            "strvn" => "Stravenue",
                            "strvnue" => "Stravenue",
                            "sumit" => "Summit",
                            "sumitt" => "Summit",
                            "summit" => "Summit",
                            "ter" => "Terrace",
                            "terr" => "Terrace",
                            "terrace" => "Terrace",
                            "throughway" => "Throughway",
                            "tpke" => "Turnpike",
                            "trace" => "Trace",
                            "traces" => "Trace",
                            "track" => "Track",
                            "tracks" => "Track",
                            "trafficway" => "Trafficway",
                            "trail" => "Trail",
                            "trailer" => "Trailer",
                            "trails" => "Trail",
                            "trak" => "Track",
                            "trce" => "Trace",
                            "trk" => "Track",
                            "trks" => "Track",
                            "trl" => "Trail",
                            "trlr" => "Trailer",
                            "trlrs" => "Trailer",
                            "trls" => "Trail",
                            "trnpk" => "Turnpike",
                            "trwy" => "Throughway",
                            "tunel" => "Tunnel",
                            "tunl" => "Tunnel",
                            "tunls" => "Tunnel",
                            "tunnel" => "Tunnel",
                            "tunnels" => "Tunnel",
                            "tunnl" => "Tunnel",
                            "turnpike" => "Turnpike",
                            "turnpk" => "Turnpike",
                            "un" => "Union",
                            "underpass" => "Underpass",
                            "union" => "Union",
                            "unions" => "Unions",
                            "valley" => "Valley",
                            "valleys" => "Valleys",
                            "vally" => "Valley",
                            "vdct" => "Viaduct",
                            "via" => "Viaduct",
                            "viadct" => "Viaduct",
                            "viaduct" => "Viaduct",
                            "view" => "View",
                            "views" => "Views",
                            "vill" => "Village",
                            "villag" => "Village",
                            "village" => "Village",
                            "villages" => "Villages",
                            "ville" => "Ville",
                            "villg" => "Village",
                            "villiage" => "Village",
                            "vis" => "Vista",
                            "vist" => "Vista",
                            "vista" => "Vista",
                            "vl" => "Ville",
                            "vlg" => "Village",
                            "vlgs" => "Villages",
                            "vlly" => "Valley",
                            "vly" => "Valley",
                            "vlys" => "Valleys",
                            "vst" => "Vista",
                            "vsta" => "Vista",
                            "vw" => "View",
                            "vws" => "Views",
                            "walk" => "Walk",
                            "walks" => "Walks",
                            "wall" => "Wall",
                            "way" => "Way",
                            "ways" => "Ways",
                            "well" => "Well",
                            "wells" => "Wells",
                            "wls" => "Wells",
                            "wy" => "Way",
                            "xing" => "Crossing",
                            "xrd" => "Crossroad",
                            "ytbd" => "(Yet to be Determined)"
                            );

    if(array_key_exists($street_name_suffix, $street_suffixes)) {
        $expanded_suffix = $street_suffixes[$street_name_suffix];
    } else {
        $expanded_suffix = $street_name_suffix;
    }

    return $expanded_suffix;
}
