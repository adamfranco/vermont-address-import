<?php

class OsmChangeConflator {

  public $nonMatchesDoc;
  public $conflictsDoc;
  public $cityConflictsDoc;
  public $matchesDoc;
  public $duplicatesInDifferentTownsDoc;
  public $reviewMultiplesDoc;
  public $reviewDistancesDoc;

  public DOMDocument $changeDoc;
  protected DOMElement $container;
  protected DOMXPath $osmXpath;
  protected DOMDocument $tempDoc;

  public function __construct(
    protected DOMDocument $osmData,
    protected $verbose = false
  ) {
    $this->osmXpath = new DOMXPath($this->osmData);
    $this->tempDoc = new DOMDocument();

    // Output targets
    $osmXmlWrapper = <<<END
<?xml version='1.0' encoding='UTF-8'?>
<osmChange version="0.6" generator="vermont-address-import">
<modify>

</modify>
</osmChange>
END;
    $this->changeDoc = new DOMDocument();
    $this->changeDoc->loadXML($osmXmlWrapper);
    $this->container = $this->changeDoc->documentElement->getElementsByTagName('modify')->item(0);
  }

  public function conflate(DOMDocument $inputDoc) {
    foreach ($inputDoc->documentElement->childNodes as $inputElement) {
      if ($inputElement->nodeName == 'node' || $inputElement->nodeName == 'way' || $inputElement->nodeName == 'relation') {
        try {
          $this->conflateOsmObject($inputElement);
        } catch (Exception $e) {
          $this->log('error', $e->getMessage());
        }
      }
    }
  }

  /**
   * Conflate a node with entries in the OSM database and return a target bucket.
   *
   * @param DOMElement $inputElement
   *   The node to compare.
   * @return null
   */
  protected function conflateOsmObject(DOMElement $inputElement) {
    // For this process we are only going to match on cases where the ESITEID
    // has already been imported into OSM. This will update objects with
    // previously imported addresses, but will not touch objects that haven't
    // yet had their addresses reviewed and merged.
    $esiteid = $this->extractESiteId($inputElement);
    $match = $this->getOsmObjectByESiteId($esiteid);

    if ($match) {
      $tempElement = $this->tempDoc->importNode($match, true);
      $originalAddress = $this->printableAddress($this->extractAddress($tempElement));
      $changed = $this->updateAddressData($tempElement, $inputElement);
      if ($changed) {
        $newAddress = $this->printableAddress($this->extractAddress($tempElement));
        $message = $this->log('changed', 'Updated address from "' . $originalAddress . '" to "' . $newAddress . '".');
        $this->append($this->changeDoc, $this->container, $tempElement, $message);
      }
      else {
        $message = $this->log('no change', 'No changes needed for "' . $originalAddress . '"');
      }
    }
    else {
      $inputAddress = $this->printableAddress($this->extractAddress($inputElement));
      $message = $this->log('not found', "No OSM object found with ref:vcgi:esiteid=" . $esiteid . ' to match "' . $inputAddress . '"');
      return;
    }
  }

  protected function log($category, $message) {
    $entry = $category . ": " . $message . "\n";
    if ($this->verbose) {
      print $entry;
      // fwrite(STDERR, $entry);
    }
    return $entry;
  }

  protected function printableAddress(array $address): string {
    return $address['addr:housenumber'] . ' '
      . (empty($address['addr:street']) ? '' : $address['addr:street'])
      . (empty($address['addr:place']) ? '' : $address['addr:place']) . ', '
      . (empty($address['addr:city']) ? '' : $address['addr:city']) . ', '
      . $address['addr:state'] . ' '
      . (empty($address['addr:postcode']) ? '' : $address['addr:postcode']);
  }

  protected function extractAddress(DOMElement $inputElement) {
    $result = [];
    foreach ($inputElement->childNodes as $child) {
      if ($child->nodeName == 'tag' && preg_match('/^addr:.+/', $child->getAttribute('k'))) {
        $result[$child->getAttribute('k')] = $child->getAttribute('v');
      }
    }
    return $result;
  }

  protected function updateAddressData(DOMElement $targetElement, DOMElement $inputElement): bool {
    $hasChanges = false;
    foreach ($inputElement->childNodes as $child) {
      if ($child->nodeName == 'tag' && preg_match('/^addr:.+/', $child->getAttribute('k'))) {
        $tagKey = $child->getAttribute('k');
        $hasTagKey = false;
        foreach ($targetElement->childNodes as $targetChild) {
          if ($targetChild->nodeName == 'tag' && $targetChild->getAttribute('k') == $tagKey) {
            $hasTagKey = true;
            if ($targetChild->getAttribute('v') != $child->getAttribute('v')) {
              $hasChanges = true;
              $targetChild->setAttribute('v', $child->getAttribute('v'));
            }
          }
        }
        if (!$hasTagKey) {
          $newTagElement = $targetElement->appendChild($targetElement->ownerDocument->createElement('tag'));
          $newTagElement->setAttribute('k', $tagKey);
          $newTagElement->setAttribute('v', $child->getAttribute('v'));
          $hasChanges = true;
        }
      }
    }
    return $hasChanges;
  }

  protected function extractESiteId(DOMElement $inputElement): string {
    foreach ($inputElement->childNodes as $child) {
      if ($child->nodeName == 'tag' && $child->getAttribute('k') == 'ref:vcgi:esiteid') {
        return $child->getAttribute('v');
      }
    }
    return null;
  }

  protected function getOsmObjectByESiteId(string $esiteid): DOMElement {
    $matches = $this->osmXpath->query('//tag[@k="ref:vcgi:esiteid" and @v="' . $esiteid . '"]/parent::*');
    if ($matches->count() == 1) {
      return $matches->item(0);
    }
    elseif ($matches->count() > 1) {
      throw new Exception($matches->count() . ' matches were found for ref:vcgi:esiteid=' . $esiteid);
    }
    else {
      return null;
    }
  }

  protected function append(DOMDocument $targetDoc, DOMElement $targetContainer, DOMElement $inputElement, $message = NULL) {
    $targetContainer->appendChild($targetDoc->createTextNode("  "));
    $newNode = $targetContainer->appendChild($targetDoc->importNode($inputElement, true));
    $targetContainer->appendChild($targetDoc->createTextNode("\n"));

    if (!empty($message)) {
      $newNode->insertBefore($targetDoc->createComment(' '.trim($message).' '), $newNode->firstChild);
      $newNode->insertBefore($targetDoc->createTextNode("\n    "), $newNode->firstChild);
    }
  }

}
