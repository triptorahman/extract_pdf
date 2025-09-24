<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;

class ZieglerPdfAssistant extends PdfClient
{
    const DEFAULT_CITY = 'N/A';
    const DEFAULT_LOADING_COMPANY = 'Loading Location';
    const DEFAULT_DELIVERY_COMPANY = 'Delivery Location';
    const DEFAULT_CARGO_TITLE = 'Palletized Cargo';
    const DEFAULT_CURRENCY = 'EUR';

    public static function validateFormat(array $lines)
    {
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
        $head = implode(' ', array_slice($lines, 0, 15));

        return Str::contains($head, 'BOOKING INSTRUCTION')
            && Str::contains($head, 'ZIEGLER UK LTD');
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $lines = array_values(array_filter(array_map('rtrim', $lines), fn($l) => $l !== ''));
        if (!static::validateFormat($lines)) {
            throw new \Exception('Invalid Ziegler PDF format');
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
            if (preg_match('/^(\d{6,11})\s+[\d,\.]+/', $line, $m)) {
                return $m[1];
            }
        }

        throw new \Exception('Ziegler reference not found.');
    }

    protected function extractFreight(array $lines): array
    {
        $price = null;
        $currency = self::DEFAULT_CURRENCY;

        foreach ($lines as $line) {
            if (preg_match('/(\d{6,11})\s+([\d,\.]+)/', $line, $m)) {
                $price = (float) str_replace(',', '', $m[2]);

                if (preg_match('/\b(EUR|GBP|USD|CHF|PLN|CZK|HUF)\b/i', $line, $currencyMatch)) {
                    $currency = strtoupper($currencyMatch[1]);
                }
                break;
            }
        }

        if ($currency === self::DEFAULT_CURRENCY) {
            foreach ($lines as $line) {
                if (preg_match('/\b(GBP|USD|CHF|PLN|CZK|HUF)\b/i', $line, $currencyMatch)) {
                    $currency = strtoupper($currencyMatch[1]);
                    break;
                }
            }
        }

        return [$price, $currency];
    }

    protected function extractCustomer(array $lines): array
    {
        $company = '';
        $street_address = '';
        $city = '';
        $postal_code = '';

        for ($i = 0; $i < min(15, count($lines)); $i++) {
            $line = trim($lines[$i]);

            if (Str::contains($line, 'ZIEGLER UK LTD')) {
                $company = str_replace('ZIEGLER UK LTD', '', $line);
                $company = trim($company);

                if (isset($lines[$i + 1])) {
                    $addressLine = trim($lines[$i + 1]);

                    if (preg_match('/^(.+)\s+([A-Z]{1,2}\d{1,2}\s+\d[A-Z]{2})$/', $addressLine, $matches)) {
                        $beforePostal = trim($matches[1]);
                        $postal_code = trim($matches[2]);

                        $parts = explode(',', $beforePostal);
                        if (count($parts) >= 2) {
                            $street_address = trim($parts[0]);
                            $city = trim($parts[1]);
                        } else {
                            $words = explode(' ', $beforePostal);
                            if (count($words) >= 3) {
                                $street_address = implode(' ', array_slice($words, 0, 2));
                                $city = implode(' ', array_slice($words, 2));
                            } else {
                                $street_address = $beforePostal;
                                $city = '';
                            }
                        }
                    }
                }
                break;
            }
        }

        return [
            'side' => 'sender',
            'details' => [
                'company' => $company,
                'street_address' => $street_address,
                'postal_code' => $postal_code,
                'city' => $city,
            ],
        ];
    }

    protected function extractStops(array $lines): array
    {
        $loading_locations = [];
        $destination_locations = [];

        foreach ($lines as $idx => $line) {
            if (Str::contains($line, 'Collection')) {
                if (preg_match('/Collection\s+(\d{4}-\d{4})\s+(\d{2}\/\d{2}\/\d{4})/', $line, $m)) {
                    $loading_locations[] = $this->parseStop($lines, $idx, 'loading', $m[1], $m[2]);
                } elseif (preg_match('/Collection\s+(\d{4}-\w+)\s+(\d{2}\/\d{2}\/\d{4})/', $line, $m)) {
                    $loading_locations[] = $this->parseStop($lines, $idx, 'loading', $m[1], $m[2]);
                } elseif (preg_match('/Collection\s+(\d{4}-\d+\w+)/', $line, $m)) {
                    $dateFound = null;
                    for ($j = $idx + 1; $j < min($idx + 3, count($lines)); $j++) {
                        if (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $lines[$j], $dateMatch)) {
                            $dateFound = $dateMatch[1];
                            break;
                        }
                    }
                    if ($dateFound) {
                        $loading_locations[] = $this->parseStop($lines, $idx, 'loading', $m[1], $dateFound);
                    }
                }
            }

            if (Str::contains($line, 'Delivery')) {
                if (preg_match('/Delivery\s+.*?(\d{2}\/\d{2}\/\d{4})/', $line, $m)) {
                    $destination_locations[] = $this->parseStop($lines, $idx, 'delivery', null, $m[1]);
                } elseif (preg_match('/Delivery\s+/', $line)) {
                    $dateFound = null;
                    for ($j = $idx + 1; $j < min($idx + 3, count($lines)); $j++) {
                        if (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $lines[$j], $dateMatch)) {
                            $dateFound = $dateMatch[1];
                            break;
                        }
                    }
                    if ($dateFound) {
                        $destination_locations[] = $this->parseStop($lines, $idx, 'delivery', null, $dateFound);
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

    protected function parseStop(array $lines, int $anchor, string $kind, ?string $timeWindow, string $date): array
    {
        $company = '';
        $addr = [];

        for ($i = $anchor + 1; $i < min($anchor + 6, count($lines)); $i++) {
            $row = trim($lines[$i]);
            if (empty($row))
                continue;

            if (Str::contains($row, ['Collection', 'Delivery', 'REF', 'WH'])) {
                continue;
            }

            if (!$company) {
                if (preg_match('/^([A-Z][A-Z\s&\(\)\/C]+(?:LTD|LIMITED|CO|INC|GMBH|SAS|SA|LOGISTICS|SOLUTIONS))\s+(.+)$/i', $row, $matches)) {
                    $company = trim($matches[1]);
                    $addr[] = trim($matches[2]);
                } elseif (preg_match('/^([A-Z][A-Z\s&\(\)\/C]+(?:LTD|LIMITED|CO|INC|GMBH|SAS|SA|LOGISTICS|SOLUTIONS))$/i', $row, $matches)) {
                    $company = trim($matches[1]);
                } elseif (preg_match('/^([A-Z][A-Z\s&\(\)\/]{8,}?)$/i', $row, $matches)) {
                    $potentialCompany = trim($matches[1]);

                    if (
                        !preg_match('/^(ROAD|STREET|AVENUE|LANE|WAY|HALL|INDUSTRIAL|ESTATE|PARK|CROSSING)/', $potentialCompany) &&
                        !preg_match('/\d/', $potentialCompany)
                    ) {
                        $company = $potentialCompany;
                    }
                }
            } else {
                $addr[] = $row;
            }

            if ($company && $i + 1 < count($lines)) {
                $nextLine = trim($lines[$i + 1]);
                if (!empty($nextLine) && !Str::contains($nextLine, ['Collection', 'Delivery', 'REF', 'WH'])) {
                    if (!preg_match('/^[A-Z][A-Z\s&\(\)\/]+(?:LTD|LIMITED|CO|INC)/', $nextLine)) {
                        $addr[] = $nextLine;
                        $i++;
                    }
                }
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

        if ($street && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $street)) {
            $street = '';
        }

        $company_address = [
            'company' => $company,
            'street_address' => $street ?: '',
            'postal_code' => $postal ?: '',
            'city' => $city,
        ];

        $dateTime = $this->parseDateTime($date, $timeWindow);

        $stop = ['company_address' => $company_address];
        if ($dateTime)
            $stop['time'] = $dateTime;

        return $stop;
    }

    protected function parseDateTime(string $date, ?string $timeWindow): ?array
    {
        try {
            $d = Carbon::createFromFormat('d/m/Y', $date);
        } catch (\Exception $e) {
            return null;
        }

        $time = [];
        if ($timeWindow) {
            if (preg_match('/(\d{4})-(\d{4})/', $timeWindow, $m)) {
                $fromHour = substr($m[1], 0, 2);
                $fromMin = substr($m[1], 2, 2);
                $toHour = substr($m[2], 0, 2);
                $toMin = substr($m[2], 2, 2);

                $from = $d->copy()->setTime((int) $fromHour, (int) $fromMin, 0, 0);
                $to = $d->copy()->setTime((int) $toHour, (int) $toMin, 0, 0);

                $time['datetime_from'] = $from->toIsoString();
                $time['datetime_to'] = $to->toIsoString();
            } elseif (preg_match('/(\d{4})-(\d+)(pm|PM)/i', $timeWindow, $m)) {
                $fromHour = substr($m[1], 0, 2);
                $fromMin = substr($m[1], 2, 2);
                $toHour = (int) $m[2];
                if ($toHour < 12 && strtolower($m[3]) === 'pm') {
                    $toHour += 12;
                }

                $from = $d->copy()->setTime((int) $fromHour, (int) $fromMin, 0, 0);
                $to = $d->copy()->setTime($toHour, 0, 0, 0);

                $time['datetime_from'] = $from->toIsoString();
                $time['datetime_to'] = $to->toIsoString();
            } else {
                $time['datetime_from'] = $d->setTime(0, 0, 0, 0)->toIsoString();
            }
        } else {
            $time['datetime_from'] = $d->setTime(0, 0, 0, 0)->toIsoString();
        }

        return $time;
    }

    protected function splitAddressTail(array $addrLines): array
    {
        $streetLines = [];
        $postal = $city = '';

        foreach ($addrLines as $row) {
            $r = trim($row);

            if (preg_match('/^([A-Z]+(?:\s+[A-Z]+)*),\s*([A-Z0-9]+\s+[A-Z0-9]+)$/', $r, $m)) {
                $city = trim($m[1]);
                $postal = trim($m[2]);
                continue;
            }

            if (preg_match('/^(.+)\s+([A-Z]+(?:\s+[A-Z]+)*),\s*([A-Z0-9]+\s+[A-Z0-9]+)$/', $r, $m)) {
                $streetLines[] = trim($m[1]);
                $city = trim($m[2]);
                $postal = trim($m[3]);
                continue;
            }

            if (preg_match('/^(.+?)\s+([A-Z0-9]{2,4}\s+[A-Z0-9]{3})\s+([A-Z\s]+)$/', $r, $m)) {
                $streetLines[] = trim($m[1]);
                $postal = trim($m[2]);
                $city = trim($m[3]);
                continue;
            }



            if (preg_match('/^(.+?\s+)?(\d{5})\s+([A-Z\s]+)$/', $r, $m)) {
                if (!empty($m[1])) {
                    $streetLines[] = trim($m[1]);
                }
                $postal = $m[2];
                $city = trim($m[3]);
                continue;
            }

            if (preg_match('/^([A-Z\s]+),\s*FR(\d{5})$/', $r, $m)) {
                $city = trim($m[1]);
                $postal = $m[2];
                continue;
            }

            $streetLines[] = $r;
        }

        $street = $streetLines ? implode(' ', $streetLines) : null;

        return [$street ?: '', $postal ?: '', $city ?: ''];
    }

    protected function extractCargos(array $lines): array
    {
        $cargos = [];

        foreach ($lines as $line) {
            if (preg_match('/(\d+)\s+PALLETS?/i', $line, $m)) {
                $packageCount = (int) $m[1];

                $cargoTitle = $this->extractCargoFromLine($line, $packageCount);
                $weight = $this->extractWeightFromLine($line, $lines);
                $packageType = $this->extractPackageTypeFromLine($line);

                $cargos[] = [
                    'title' => $cargoTitle ?: self::DEFAULT_CARGO_TITLE,
                    'package_count' => $packageCount,
                    'package_type' => $packageType,
                    'weight' => $weight,
                ];
            }
        }

        if (!$cargos) {
            throw new \Exception('Cargo details not found.');
        }

        return $cargos;
    }

    protected function extractCargoFromLine(string $line, int $packageCount): string
    {
        $cargoText = preg_replace('/\s*' . $packageCount . '\s+PALLETS?/i', '', $line);
        $cargoText = trim($cargoText);

        if (preg_match('/REF\s+\d+\s+pallets?\s+REF\s+([A-Z0-9]+)/i', $line, $m)) {
            return $m[1];
        }

        if (preg_match('/REF\s+\d+\s+PALLETS?\s+REF\s+([A-Z0-9]+)/i', $line, $m)) {
            return $m[1];
        }

        if (preg_match('/REF\s+REF\s+([A-Z0-9]+)/i', $cargoText, $m)) {
            return $m[1];
        }

        if (preg_match('/REF\s+([A-Z0-9]+)/i', $cargoText, $m)) {
            return $m[1];
        }

        if (preg_match('/WH\s+([A-Z0-9]+)/i', $cargoText, $m)) {
            return $m[1];
        }

        if (!empty($cargoText) && $cargoText !== 'REF' && strlen($cargoText) > 2) {
            if (preg_match('/([A-Z0-9]{3,})/i', $cargoText, $m)) {
                return $m[1];
            }
        }

        return '';
    }

    protected function extractWeightFromLine(string $line, array $allLines): float
    {
        if (preg_match('/(\d{1,4}(?:,\d{3})*(?:\.\d+)?)\s*KG/i', $line, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        if (preg_match('/(?:WEIGHT|WT):\s*(\d{1,4}(?:,\d{3})*(?:\.\d+)?)/i', $line, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        $lineIndex = array_search($line, $allLines);
        if ($lineIndex !== false) {
            $searchRange = range(max(0, $lineIndex - 2), min(count($allLines) - 1, $lineIndex + 2));

            foreach ($searchRange as $idx) {
                $searchLine = $allLines[$idx];
                if (preg_match('/(\d{1,4}(?:,\d{3})*(?:\.\d+)?)\s*KG/i', $searchLine, $m)) {
                    return (float) str_replace(',', '', $m[1]);
                }
            }
        }

        return 0;
    }

    protected function extractPackageTypeFromLine(string $line): string
    {
        if (preg_match('/\bpallets?\b/i', $line)) {
            return 'pallet';
        }

        if (preg_match('/\b(?:boxes?|cartons?)\b/i', $line)) {
            return 'box';
        }

        if (preg_match('/\b(?:crates?|cases?)\b/i', $line)) {
            return 'crate';
        }

        if (preg_match('/\b(?:drums?|barrels?)\b/i', $line)) {
            return 'drum';
        }

        if (preg_match('/\b(?:bags?|sacks?)\b/i', $line)) {
            return 'bag';
        }

        if (preg_match('/\b(?:rolls?|coils?)\b/i', $line)) {
            return 'roll';
        }

        if (preg_match('/\b(?:containers?|units?)\b/i', $line)) {
            return 'container';
        }

        return 'pallet';
    }
}
