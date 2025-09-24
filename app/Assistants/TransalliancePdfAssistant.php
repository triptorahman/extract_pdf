<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;

class TransalliancePdfAssistant extends PdfClient
{
    // Configurable constants
    const DEFAULT_LOADING_COMPANY = 'Loading Location';
    const DEFAULT_DELIVERY_COMPANY = 'Delivery Location';
    const DEFAULT_PACKAGE_TYPE = 'pallet';
    const DEFAULT_CITY = 'Unknown';
    const HEADER_SCAN_LINES = 20;
    const STOP_SCAN_LINES = 15;
    const ADDRESS_SCAN_LINES = 8;
    const CARGO_SCAN_LINES = 10;
    const CONTEXT_SCAN_LINES = 5;
    const DATE_SEARCH_LINES = 6;

    public static function validateFormat(array $lines)
    {
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
        $head = implode(' ', array_slice($lines, 0, self::HEADER_SCAN_LINES));

        return Str::contains($head, 'CHARTERING CONFIRMATION')
            && Str::contains($head, 'TRANSALLIANCE TS LTD');
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $lines = array_values(array_filter(array_map('rtrim', $lines), fn($l) => $l !== ''));
        if (!static::validateFormat($lines)) {
            throw new \Exception('Invalid Transalliance PDF format');
        }

        $order_reference = $this->extractOrderRef($lines);
        [$freight_price, $freight_currency] = $this->extractFreight($lines);

        $customer = $this->extractCustomer($lines);

        [$loading_locations, $destination_locations] = $this->extractStops($lines);

        $cargos = $this->extractCargos($lines);

        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];

        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'cargos',
            'order_reference',
            'freight_price',
            'freight_currency',
            'attachment_filenames'
        );

        $this->createOrder($data);
    }



    protected function extractOrderRef(array $lines): string
    {
        foreach ($lines as $line) {
            if (preg_match('/^REF\.\s*:\s*([A-Z0-9]+)/i', $line, $m)) {
                return $m[1];
            }
        }
        throw new \Exception('Order reference not found.');
    }

    protected function extractFreight(array $lines): array
    {
        $price = null;
        $cur = null;
        foreach ($lines as $line) {
            if (
                Str::contains($line, 'SHIPPING PRICE')
                && preg_match('/SHIPPING PRICE\s+([\d.,]+)\s+(EUR|USD|GBP|PLN|ZAR)/i', $line, $m)
            ) {
                $price = uncomma($m[1]);
                $cur = strtoupper($m[2]);
                break;
            }
        }
        return [$price, $cur];
    }

    protected function extractCustomer(array $lines): array
    {
        foreach ($lines as $i => $line) {
            $trimmedLine = trim($line);

            if (Str::contains($trimmedLine, 'Contact:')) {
                if (preg_match('/^([A-Z][A-Z\s&\.\(\)\/0-9]+?)\s+(.+?)\s+Contact:/i', $trimmedLine, $matches)) {
                    $company = trim($matches[1]);
                    $address = trim($matches[2]);
                } elseif (preg_match('/^(.+?)\s+Contact:/i', $trimmedLine, $matches)) {
                    $fullText = trim($matches[1]);
                    if (preg_match('/^([A-Z][A-Z\s&\.\(\)\/0-9]+?)\s+(.+)$/i', $fullText, $splitMatches)) {
                        $company = trim($splitMatches[1]);
                        $address = trim($splitMatches[2]);
                    } else {
                        $company = $fullText;
                        $address = '';
                    }
                }
            } elseif (preg_match('/^(Test\s+Client\s+\d+)/i', $trimmedLine, $matches)) {
                $company = trim($matches[1]);
                $address = '';

                if (preg_match('/' . preg_quote($company, '/') . '\s+(.+?)\s+Contact:/i', $trimmedLine, $addr_match)) {
                    $address = trim($addr_match[1]);
                } elseif (isset($lines[$i + 1])) {
                    $nextLine = $lines[$i + 1];
                    if (preg_match('/^(.+?)\s+Contact:/i', $nextLine, $addr_match)) {
                        $address = trim($addr_match[1]);
                    } else {
                        if (preg_match('/[A-Z]{2}-\d{5}/', $nextLine)) {
                            $address = trim(preg_replace('/\s+Contact:.*$/', '', $nextLine));
                        }
                    }
                }
            }

            if (isset($company) && $company) {

                $street = '';
                $postal = '';
                $city = '';

                if (preg_match('/(.+?)\s+([A-Z]{2}-\d{5})\s+(.+?)(?:\s+Contact:|$)/i', $address, $addr_parts)) {
                    $street = trim($addr_parts[1]);
                    $postal = $addr_parts[2];
                    $city = trim($addr_parts[3]);
                }

                if (!$city || strlen($city) < 2) {
                    $city = self::DEFAULT_CITY;
                }

                return [
                    'side' => 'sender',
                    'details' => [
                        'company' => $company,
                        'street_address' => $street ?: '',
                        'postal_code' => $postal ?: '',
                        'city' => $city,
                    ],
                ];
            }
        }

        throw new \Exception('Customer information not found.');
    }

    protected function extractStops(array $lines): array
    {
        $loading_locations = [];
        $destination_locations = [];

        foreach ($lines as $idx => $line) {
            if (trim($line) === 'Loading') {
                for ($j = $idx + 1; $j < min($idx + self::DATE_SEARCH_LINES, count($lines)); $j++) {
                    $searchLine = trim($lines[$j]);
                    if (
                        Str::contains($searchLine, 'ON:') ||
                        preg_match('/\d{2}\/\d{2}\/\d{2}/', $searchLine) ||
                        preg_match('/\d{1,2}h\d{2}\s*-\s*\d{1,2}h\d{2}/', $searchLine)
                    ) {
                        $loading_locations[] = $this->parseStop($lines, $j, 'loading');
                        break;
                    }
                }
            }
            if (trim($line) === 'Delivery') {
                for ($j = $idx + 1; $j < min($idx + self::DATE_SEARCH_LINES, count($lines)); $j++) {
                    $searchLine = trim($lines[$j]);
                    if (
                        Str::contains($searchLine, 'ON:') ||
                        preg_match('/\d{2}\/\d{2}\/\d{2}/', $searchLine) ||
                        preg_match('/\d{1,2}h\d{2}\s*-\s*\d{1,2}h\d{2}/', $searchLine)
                    ) {
                        $destination_locations[] = $this->parseStop($lines, $j, 'delivery');
                        break;
                    }
                }
            }
        }

        if (!$loading_locations) {
            throw new \Exception('Loading location not found.');
        }
        if (!$destination_locations) {
            throw new \Exception('Destination location not found.');
        }

        return [$loading_locations, $destination_locations];
    }

    protected function extractCompanyAndAddress(string $row, array $lines, int $currentIndex, string $kind): array
    {
        $company = '';
        $addr = [];

        $patterns = [
            '/^([A-Z][A-Z\s&\.,\(\)0-9]+?(?:LTD|GMBH|SA|SL|BV|GROUP|COMPANY|CORP|INC|PORT|WORLD|GATEWAY)?)\s+(.+?)\s+(GB-[A-Z0-9\s]+)\s+([A-Z\s]+?)(?:\s+Contact:|$)/i',
            '/^([A-Z][A-Z\s&\.,\(\)0-9]+?(?:LTD|GMBH|SA|SL|BV|GROUP|COMPANY|CORP|INC|FRANCE)?)\s+(.+?)\s+(\d{5})\s+([A-Z\s\-]+?)(?:\s+Contact:|$)/i',
            '/^([A-Z][A-Z\s&\.,\(\)0-9]+?(?:LTD|GMBH|SA|SL|BV|GROUP|COMPANY|CORP|INC)?)\s+(.+?)\s+([A-Z]{2}-?[\d\s]+)\s+([A-Z\s\-]+?)(?:\s+Contact:|$)/i',
        ];

        $excludedWords = $this->getExcludedCompanyWords();

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $row, $matches)) {
                $potentialCompany = trim($matches[1]);

                if (!in_array(strtoupper($potentialCompany), $excludedWords)) {
                    $company = $potentialCompany;
                    $addr[] = trim($matches[2]);

                    if (isset($matches[4])) {
                        if (Str::startsWith($matches[3], 'GB-') || preg_match('/^[A-Z]{2}-/', $matches[3])) {
                            $addr[] = trim($matches[3]) . ' ' . trim($matches[4]);
                        } else {
                            $addr[] = trim($matches[3]) . ' ' . str_replace('-', ' ', trim($matches[4]));
                        }
                    } else {
                        $addr[] = trim($matches[3]);
                    }
                    break;
                }
            }
        }

        if (!$company && $this->isStandaloneCompanyName($row)) {
            $company = trim($row);
            $addr = $this->findAddressLines($lines, $currentIndex + 1);
        }

        return ['company' => $company, 'address' => $addr];
    }

    protected function getExcludedCompanyWords(): array
    {
        return ['REFERENCE', 'INSTRUCTIONS', 'OT', 'LM', 'LOADING', 'DELIVERY', 'ON', 'CONTACT'];
    }

    protected function isStandaloneCompanyName(string $line): bool
    {
        $line = trim($line);

        return preg_match('/^[A-Z][A-Z\s&\.,\(\)0-9]+$/i', $line)
            && (Str::contains($line, ['LTD', 'GMBH', 'SA', 'SL', 'BV', 'GROUP', 'COMPANY', 'CORP', 'INC', 'PORT', 'WORLD'])
                || strlen($line) > 5);
    }

    protected function findAddressLines(array $lines, int $startIndex): array
    {
        $addr = [];

        for ($j = $startIndex; $j < min($startIndex + self::ADDRESS_SCAN_LINES, count($lines)); $j++) {
            $addrLine = trim($lines[$j]);

            if (empty($addrLine))
                continue;

            if (preg_match('/^[A-Z0-9\s\.,\-]+(?:STREET|ST|ROAD|RD|AVENUE|AVE|LANE|LN|WAY|DRIVE|DR)$/i', $addrLine)) {
                $addr[] = $addrLine;
            } elseif (preg_match('/^(GB-[A-Z0-9\s]+\s+[A-Z\s]+|[A-Z]{2}-[\d\s]+\s+[A-Z\s]+|\d{5}\s+[A-Z\s\-]+)$/i', $addrLine)) {
                $addr[] = $addrLine;
                break;
            } elseif (preg_match('/^[A-Z0-9\s\.,\-\/]+$/i', $addrLine) && !Str::contains($addrLine, 'Contact:')) {
                $addr[] = $addrLine;
            }
        }

        return $addr;
    }

    protected function parseStop(array $lines, int $anchor, string $kind): array
    {
        [$dtFrom, $dtTo] = $this->parseDateWindow($lines[$anchor]);

        $company = '';
        $addr = [];

        for ($i = $anchor + 1; $i < min($anchor + self::STOP_SCAN_LINES, count($lines)); $i++) {
            $row = trim($lines[$i]);

            if (empty($row))
                continue;

            $extracted = $this->extractCompanyAndAddress($row, $lines, $i, $kind);

            if ($extracted['company']) {
                $company = $extracted['company'];
                $addr = $extracted['address'];
                break;
            }
        }

        [$street, $postal, $city] = $this->splitAddressTail($addr);

        if (!$company) {
            $company = $kind === 'loading' ? self::DEFAULT_LOADING_COMPANY : self::DEFAULT_DELIVERY_COMPANY;
        }

        if (!$city || strlen($city) < 2) {
            $city = self::DEFAULT_CITY;
        }

        $company_address = [
            'company' => $company,
            'street_address' => $street ?: '',
            'postal_code' => $postal ?: '',
            'city' => $city,
        ];

        $time = $dtFrom ? ['datetime_from' => $dtFrom] : null;
        if ($dtFrom && $dtTo)
            $time['datetime_to'] = $dtTo;

        $stop = ['company_address' => $company_address];
        if ($time)
            $stop['time'] = $time;

        return $stop;
    }

    protected function parseDateWindow(string $onLine): array
    {
        $from = null;
        $to = null;

        if (preg_match('/ON:\s*(\d{2}\/\d{2}\/\d{2})(?:\s+(\d{1,2})h(\d{2})\s*-\s*(\d{1,2})h(\d{2}))?/i', $onLine, $m)) {
            try {
                $d = Carbon::createFromFormat('d/m/y', $m[1]);
                $from = $d->copy();

                if (!empty($m[2])) {
                    $from->setTime((int) $m[2], (int) $m[3], 0, 0);
                    $to = $d->copy()->setTime((int) $m[4], (int) $m[5], 0, 0);
                } else {
                    $from->setTime(0, 0, 0, 0);
                }
            } catch (\Exception $e) {
                return [null, null];
            }
        } elseif (preg_match('/(\d{2}\/\d{2}\/\d{2})\s+(\d{1,2})h(\d{2})\s*-\s*(\d{1,2})h(\d{2})/i', $onLine, $m)) {
            try {
                $d = Carbon::createFromFormat('d/m/y', $m[1]);
                $from = $d->copy()->setTime((int) $m[2], (int) $m[3], 0, 0);
                $to = $d->copy()->setTime((int) $m[4], (int) $m[5], 0, 0);
            } catch (\Exception $e) {
                return [null, null];
            }
        } elseif (preg_match('/(\d{2}\/\d{2}\/\d{2})/i', $onLine, $m)) {
            try {
                $d = Carbon::createFromFormat('d/m/y', $m[1]);
                $from = $d->copy()->setTime(0, 0, 0, 0);
            } catch (\Exception $e) {
                return [null, null];
            }
        } else {
            return [null, null];
        }

        return [$from ? $from->toIsoString() : null, $to ? $to->toIsoString() : null];
    }

    protected function splitAddressTail(array $addrLines): array
    {
        $streetLines = [];
        $postal = $city = null;

        foreach ($addrLines as $row) {
            $r = trim($row);

            if (preg_match('/^([A-Z]{2})-([A-Z0-9\s]+)\s+(.+)$/i', $r, $m)) {
                $postal = $m[1] . '-' . trim($m[2]);
                $city = trim($m[3]);
                continue;
            }

            if (preg_match('/^(\d{5})\s+(.+)$/i', $r, $m)) {
                $postal = $m[1];
                $city = str_replace('-', ' ', trim($m[2]));
                continue;
            }

            $streetLines[] = $r;
        }

        $street = $streetLines ? implode(' ', $streetLines) : null;
        return [$street, $postal, $city];
    }

    protected function extractCargos(array $lines): array
    {
        $cargos = [];
        foreach ($lines as $i => $line) {
            if (Str::startsWith($line, 'M. nature:')) {
                $title = trim(Str::after($line, 'M. nature:'));
                $weight = null;
                $package_count = null;
                $package_type = null;

                for ($j = $i - 1; $j >= max(0, $i - self::CARGO_SCAN_LINES); $j--) {
                    $checkLine = trim($lines[$j]);

                    if (preg_match('/^(\d{1,2},\d{3})\s+(\d{5},\d{3})$/', $checkLine, $wm)) {
                        $lm_value = str_replace(',', '', $wm[1]);
                        $weight = (float) str_replace(',', '', $wm[2]);
                        $package_count = (int) $lm_value > 0 ? (int) $lm_value : 1;
                        $package_type = $this->determinePackageType($title, $lines, $i);
                        break;
                    } elseif (preg_match('/^(\d{1,4}(?:,\d{3})*)\s+(\d{3,}(?:,\d{3})*)$/', $checkLine, $wm)) {
                        $lm_value = str_replace(',', '', $wm[1]);
                        $weight = (float) str_replace(',', '', $wm[2]);
                        $package_count = (int) $lm_value > 0 ? (int) $lm_value : 1;
                        $package_type = $this->determinePackageType($title, $lines, $i);
                        break;
                    } elseif (preg_match('/^(\d{4,}(?:,\d{3})*)$/', $checkLine, $wm)) {
                        $weight = (float) str_replace(',', '', $wm[1]);
                        $package_count = 1;
                        $package_type = $this->determinePackageType($title, $lines, $i);
                        break;
                    }
                }

                if (!$weight || $weight === 0) {
                    for ($j = max(0, $i - 15); $j <= min($i + 5, count($lines) - 1); $j++) {
                        $searchLine = trim($lines[$j]);
                        if (preg_match('/(\d{4,}(?:,\d{3})*)/', $searchLine, $wm)) {
                            $potential_weight = (float) str_replace(',', '', $wm[1]);
                            if ($potential_weight >= 1000 && $potential_weight <= 100000000) {
                                $weight = $potential_weight;
                                break;
                            }
                        }
                    }
                }

                if (!$weight || $weight === 0) {
                    $weight = 0;
                }

                $cargos[] = [
                    'title' => $title ?: null,
                    'package_count' => $package_count ?: 1,
                    'package_type' => $package_type ?: 'pallet',
                    'weight' => $weight,
                ];
            }
        }

        if (!$cargos) {
            throw new \Exception('Cargo details not found.');
        }
        return $cargos;
    }

    protected function determinePackageType(string $title, array $lines, int $contextIndex): string
    {
        $title_lower = strtolower($title);

        if (Str::contains($title_lower, ['pallet', 'pal'])) {
            return 'pallet';
        }
        if (Str::contains($title_lower, ['box', 'carton', 'package'])) {
            return 'box';
        }
        if (Str::contains($title_lower, ['roll', 'coil'])) {
            return 'pallet';
        }
        if (Str::contains($title_lower, ['container', 'ctn'])) {
            return 'container';
        }

        for ($i = max(0, $contextIndex - 5); $i < min($contextIndex + 5, count($lines)); $i++) {
            $line_lower = strtolower(trim($lines[$i]));
            if (Str::contains($line_lower, 'pallet')) {
                return 'pallet';
            }
        }

        return 'pallet';
    }
}
