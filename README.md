# vermont-address-import

Scripts and data for importing VCGI addresses into OpenStreetMap.

Project proposal here: https://wiki.openstreetmap.org/wiki/VCGI_E911_address_points_import

## Usage

### Basic usage

1. Download the latest [VT E911 Site/Structure Address Points (SSAP)](https://geodata.vermont.gov/datasets/13bf900a1da249aeb35b01fd86cb74e1_0/explore?location=43.870101%2C-72.457621%2C8) file as GeoJSON.

2. Update all data files
   ```
   time ./update_all_data -v -p 8 ~/Downloads/FS_VCGI_OPENDATA_Emergency_SiteStructureAddressPoint_point_SP_v1_VIEW_5282602460236465195
   ```

### Manual step-by-step usage

#### Download fresh E911 data
1. Download the latest [VT E911 Site/Structure Address Points (SSAP)](https://geodata.vermont.gov/datasets/13bf900a1da249aeb35b01fd86cb74e1_0/explore?location=43.870101%2C-72.457621%2C8) file as GeoJSON.

2. Extract the full-state GeoJSON file into per-town files
   ```
   ./extract_town_points.php -v FS_VCGI_OPENDATA_Emergency_SiteStructureAddressPoint_point_SP_v1_VIEW_5282602460236465195
   ```

#### Regenerate all draft data files:
```
./generate_all.php -v
```

Help:
```
Generate new output files in data_files_to_import/draft/ for every
input file in town_e911_address_points/

./generate_all.php [-hv] [--help] [--verbose]

  -h --help           Show this help
  -v --verbose        Print status output.

  <file.geojson>      The input geojson file.

```

Combine all town-by-town files into a single state-wide file with:
```
./combine-all-points
```

#### Generate a single data file:
```
./generate_osm_file_from_e911_geojson.php <file>
```

Help:
```
./generate_osm_file_from_e911_geojson.php [-hv] [--help] [--verbose] [--output-type=osm|tab|geojson] <file.geojson>

  -h --help           Show this help
  -v --verbose        Print errors at the end.
  --output-type       Format of the output, default is osm.

  <file.geojson>      The input geojson file.
```


#### Conflation

Requires `php-sqlite` (maybe version-specific on your platform, e.g. `php81-sqlite`) and `spatialite`.
Your `php.ini` probably needs the location of `mod_spatialite.so` added. Something like:

    sqlite3.extension_dir = /opt/local/lib/

1. (optional) Download/refresh the existing Vermont address center points from OSM via OverPass.

    php -d memory_limit=1G download_osm_addresses.php

2. (optional) Rebuild the SQLite database holding these address points.

    ./reload_osm_addresses.php osm_data/osm_addresses.osm

3. Generate bucketed data-sets for a town. This will go through all of the
   addresses in the input file and see if they exist in the local database of OSM
   addresses.

   If there is a perfect match on tags they will be placed in an `osm_match/`
   file, or an `osm_reviews/` file if there are multiple matches or a large offset.

   If there is a likely candidate for the address that has different tags, the
   input will be placed in the `osm_conflict/` file.

   If there isn't any likely candidate for the adddress, it will be placed in a
   `no_osm_match/` file.

   a. Conflate a single town:

        ./conflate_town.php -v data_files_to_import/draft/middlebury_addresses.osm

      The verbose output can be examined to see why the address got categorized the
      way it did.

   b. Conflate all towns:

        ./conflate_all.php -v

Note that the conflation process is mostly CPU limited while doing proximity
lookups when address matches don't exist yet in OSM. In similar-sized towns, the
conflation process will go much faster in those with good address coverage than
without. Because the conflation process is single-threaded it is possible to
utilize multiple CPUs (if available) by running multiple conflation jobs for
different ranges of town names.

The Vermont E911 data set currently has about 350,000 address points.
For example, you could open 4 bash shells and run one of the following commands
in each one:

- `./conflate_all.php -v --name-range=-concord`
- `./conflate_all.php -v --name-range=corinth-maidstone`
- `./conflate_all.php -v --name-range=manchester-shaftsbury`
- `./conflate_all.php -v --name-range=sharon-`

This will saturate 4 processor cores and cover about 87,000 input address points
on each.

To divide across 3 cores the approximately equal ranges would be:
- `./conflate_all.php -v --name-range=-fair_haven`
- `./conflate_all.php -v --name-range=fairfax-richmond`
- `./conflate_all.php -v --name-range=ripton-`

To divide across 8 cores the approximately equal ranges would be:
- `./conflate_all.php -v --name-range=-bridgewater`
- `./conflate_all.php -v --name-range=bridport-concord`
- `./conflate_all.php -v --name-range=corinth-granville`
- `./conflate_all.php -v --name-range=greensboro-maidstone`
- `./conflate_all.php -v --name-range=manchester-pittsfield`
- `./conflate_all.php -v --name-range=pittsfield-shaftsbury`
- `./conflate_all.php -v --name-range=sharon-vernon`
- `./conflate_all.php -v --name-range=vershire-`

The `osm_data/osm_addresses.sqlite` file and
`data_files_to_import/draft/*_addresses.osm` files committed to this repository
contain all of the data needed to run the conflation and write ouptut to
`data_files_to_import/conflated/`. As such, these jobs could even be split
across multiple physical machines if desired.

If you need to kill and restart the process, the `conflate_all.php` script supports
a `--skip-existing` option to skip any towns where the output files already exist.

#### Combining outputs

Now that the full address set for the state has been imported, incremental updates
can be easier to do from a single file for all towns rather than many small town-
by-town files.

Merge all conflated files with
```
./combine-conflated-changes
```
