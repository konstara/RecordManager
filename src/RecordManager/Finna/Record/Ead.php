<?php
/**
 * Ead record class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012-2017.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Finna\Record;

use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * Ead record class
 *
 * This is a class for processing EAD records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Ead extends \RecordManager\Base\Record\Ead
{
    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        $doc = $this->doc;

        $unitDateRange = $this->parseDateRange((string)$doc->did->unitdate);
        $data['search_daterange_mv'] = $data['unit_daterange']
            = MetadataUtils::dateRangeToStr($unitDateRange);
        if ($unitDateRange) {
            $data['main_date_str'] = MetadataUtils::extractYear($unitDateRange[0]);
            $data['main_date'] = $this->validateDate($unitDateRange[0]);
            // Append year range to title (only years, not the full dates)
            $startYear = MetadataUtils::extractYear($unitDateRange[0]);
            $endYear = MetadataUtils::extractYear($unitDateRange[1]);
            $yearRange = '';
            if ($startYear != '-9999') {
                $yearRange = $startYear;
            }
            if ($endYear != $startYear) {
                $yearRange .= '-';
                if ($endYear != '9999') {
                    $yearRange .= $endYear;
                }
            }
            if ($yearRange) {
                $len = strlen($yearRange);
                foreach (
                    ['title_full', 'title_sort', 'title', 'title_short']
                    as $field
                ) {
                    if (substr($data[$field], -$len) != $yearRange
                        && substr($data[$field], -$len - 2) != "($yearRange)"
                    ) {
                        $data[$field] .= " ($yearRange)";
                    }
                }
            }
        }

        // Single-valued sequence for sorting
        if (isset($data['hierarchy_sequence'])) {
            $data['hierarchy_sequence_str'] = $data['hierarchy_sequence'];
        }

        $data['source_str_mv'] = isset($data['institution'])
            ? $data['institution'] : $this->source;
        $data['datasource_str_mv'] = $this->source;

        // Digitized?
        if ($doc->did->daogrp) {
            if (in_array($data['format'], ['collection', 'series', 'fonds', 'item'])
            ) {
                $data['format'] = 'digitized_' . $data['format'];
            }

            if ($this->doc->did->daogrp->daoloc) {
                foreach ($this->doc->did->daogrp->daoloc as $daoloc) {
                    if ($daoloc->attributes()->{'href'}) {
                        $data['online_boolean'] = true;
                        // This is sort of special. Make sure to use source instead
                        // of datasource.
                        $data['online_str_mv'] = $data['source_str_mv'];
                        break;
                    }
                }
            }
        }

        if (isset($doc->did->unitid)) {
            $data['identifier'] = (string)$doc->did->unitid;
        }
        if (isset($doc->did->dimensions)) {
            // display measurements
            $data['measurements'] = (string)$doc->did->dimensions;
        }

        if (isset($doc->did->physdesc)) {
            $data['material'] = (string)$doc->did->physdesc;
        }

        if (isset($doc->did->userestrict->p)) {
            $data['rights'] = (string)$doc->did->userestrict->p;
        } elseif (isset($doc->did->accessrestrict->p)) {
            $data['rights'] = (string)$doc->did->accessrestrict->p;
        }

        // Usage rights
        if ($rights = $this->getUsageRights()) {
            $data['usage_rights_str_mv'] = $rights;
        }

        return $data;
    }

    /**
     * Return usage rights if any
     *
     * @return array ['restricted'] or a more specific id if restricted,
     * empty array otherwise
     */
    protected function getUsageRights()
    {
        if (isset($this->doc->userestrict->p)) {
            foreach ($this->doc->userestrict->p as $restrict) {
                if (strstr((string)$restrict, 'No known copyright restrictions')) {
                    return ['No known copyright restrictions'];
                }
            }
        }

        if (isset($this->doc->accessrestrict->p)) {
            foreach ($this->doc->accessrestrict->p as $restrict) {
                if (strstr((string)$restrict, 'No known copyright restrictions')) {
                    return ['No known copyright restrictions'];
                }
            }
        }
        return ['restricted'];
    }

    /**
     * Parse date range string
     *
     * @param string $input Date range
     *
     * @return NULL|array
     */
    protected function parseDateRange($input)
    {
        if (!$input || $input == '-') {
            return null;
        }

        if (true
            && preg_match(
                '/(\d\d?).(\d\d?).(\d\d\d\d) ?- ?(\d\d?).(\d\d?).(\d\d\d\d)/',
                $input,
                $matches
            ) > 0
        ) {
            $startYear = $matches[3];
            $startMonth = sprintf('%02d', $matches[2]);
            $startDay = sprintf('%02d', $matches[1]);
            $startDate = $startYear . '-' . $startMonth . '-' . $startDay
                . 'T00:00:00Z';
            $endYear = $matches[6];
            $endMonth = sprintf('%02d', $matches[5]);
            $endDay = sprintf('%02d', $matches[4]);
            $endDate = $endYear . '-' . $endMonth . '-' . $endDay . 'T23:59:59Z';
        } elseif (true
            && preg_match(
                '/(\d\d?).(\d\d\d\d) ?- ?(\d\d?).(\d\d\d\d)/', $input, $matches
            ) > 0
        ) {
            $startYear = $matches[2];
            $startMonth = sprintf('%02d', $matches[1]);
            $startDay = '01';
            $startDate = $startYear . '-' . $startMonth . '-' . $startDay
                . 'T00:00:00Z';
            $endYear = $matches[4];
            $endMonth = sprintf('%02d', $matches[3]);
            $endDate = $endYear . '-' . $endMonth . '-01';
            try {
                $d = new \DateTime($endDate);
            } catch (\Exception $e) {
                $this->logger->log(
                    'Ead',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID(),
                    Logger::WARNING
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d\d\d) ?- ?(\d\d\d\d)/', $input, $matches) > 0) {
            $startDate = $matches[1] . '-01-01T00:00:00Z';
            $endDate = $matches[2] . '-12-31T00:00:00Z';
        } elseif (preg_match('/(\d\d\d\d)-(\d\d?)-(\d\d?)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[3]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d?).(\d\d?).(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[3];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d?)\.(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[2];
            $month = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-01' . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new \DateTime($endDate);
            } catch (\Exception $e) {
                $this->logger->log(
                    'Ead',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID(),
                    Logger::WARNING
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
        } elseif (preg_match('/(\d+) ?- ?(\d+)/', $input, $matches) > 0) {
            $startDate = $matches[1] . '-01-01T00:00:00Z';
            $endDate = $matches[2] . '-12-31T00:00:00Z';
        } elseif (preg_match('/(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];
            $startDate = $year . '-01-01T00:00:00Z';
            $endDate = $year . '-12-31T23:59:59Z';
        } else {
            return null;
        }

        if (strtotime($startDate) > strtotime($endDate)) {
            $this->logger->log(
                'Ead',
                "Invalid date range {$startDate} - {$endDate}, record "
                    . "{$this->source}." . $this->getID(),
                Logger::WARNING
            );
            $this->storeWarning('invalid date range');
            $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
        }

        return [$startDate, $endDate];
    }
}
