<?php
/**
 * Shared DNS helpers for the 20i CLI tools.
 *
 * This file is part of a software project licensed under the
 * GNU General Public License v3.0.
 *
 * Copyright (C) 2026 Stephen Amerige
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Original Author: Stephen Amerige, Raleigh, North Carolina
 * Created: July 12, 2026
 */

declare(strict_types=1);

namespace SoftwareWrap\TwentyI\Dns;

use InvalidArgumentException;
use RuntimeException;

const TYPE_TXT = 'TXT';

const DNS_CLASS_IN = 1;
const DNS_TYPE_A = 1;
const DNS_TYPE_NS = 2;
const DNS_TYPE_CNAME = 5;
const DNS_TYPE_SOA = 6;
const DNS_TYPE_PTR = 12;
const DNS_TYPE_MX = 15;
const DNS_TYPE_TXT = 16;
const DNS_TYPE_AAAA = 28;
const DNS_TYPE_SRV = 33;

const DNS_FLAG_QR = 0x8000;
const DNS_FLAG_AA = 0x0400;
const DNS_FLAG_TC = 0x0200;
const DNS_RCODE_MASK = 0x000F;

const DNS_RCODE_NOERROR = 0;
const DNS_RCODE_NXDOMAIN = 3;

const DEFAULT_DNS_PORT = 53;
const DEFAULT_DNS_TIMEOUT_SECONDS = 5;

const DEFAULT_STACKDNS_NAMESERVERS = [
    'ns1.stackdns.com',
    'ns2.stackdns.com',
    'ns3.stackdns.com',
    'ns4.stackdns.com',
];

/**
 * Normalize a DNS record type.
 */
function normalizeRecordType(string $type): string
{
    return strtoupper(trim($type));
}

/**
 * Determine whether a DNS record type is currently supported.
 */
function isSupportedRecordType(string $type): bool
{
    return normalizeRecordType($type) === TYPE_TXT;
}

/**
 * Validate and return a supported DNS record type.
 */
function requireSupportedRecordType(string $type): string
{
    $type = normalizeRecordType($type);

    if (!isSupportedRecordType($type)) {
        throw new InvalidArgumentException(
            "Record type '{$type}' is not currently supported. Supported types: TXT"
        );
    }

    return $type;
}

/**
 * Normalize a DNS record name.
 */
function normalizeRecordName(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        throw new InvalidArgumentException(
            'The DNS record name cannot be empty. Use @ for the zone apex.'
        );
    }

    if ($name === '@') {
        return '@';
    }

    return strtolower(rtrim($name, '.'));
}

/**
 * Determine whether a DNS record name is syntactically valid.
 */
function isValidRecordName(string $name): bool
{
    try {
        $name = normalizeRecordName($name);
    } catch (InvalidArgumentException $exception) {
        return false;
    }

    if ($name === '@') {
        return true;
    }

    if (strlen($name) > 253) {
        return false;
    }

    foreach (explode('.', $name) as $label) {
        if ($label === '' || strlen($label) > 63) {
            return false;
        }

        if (preg_match('/^[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?$/i', $label) !== 1) {
            return false;
        }
    }

    return true;
}

/**
 * Validate and return a DNS record name.
 */
function requireValidRecordName(string $name): string
{
    $name = normalizeRecordName($name);

    if (!isValidRecordName($name)) {
        throw new InvalidArgumentException("Invalid DNS record name '{$name}'.");
    }

    return $name;
}

/**
 * Normalize a TXT record value for comparison and submission.
 */
function normalizeTxtValue(string $value): string
{
    return trim($value);
}

/**
 * Validate and return a TXT record value.
 */
function requireValidTxtValue(string $value): string
{
    $value = normalizeTxtValue($value);

    if ($value === '') {
        throw new InvalidArgumentException('The TXT record value cannot be empty.');
    }

    return $value;
}

/**
 * Determine whether two TXT values are identical after normalization.
 */
function txtValuesEqual(string $left, string $right): bool
{
    return normalizeTxtValue($left) === normalizeTxtValue($right);
}

/**
 * Build one normalized TXT record definition.
 *
 * @return array{host:string,txt:string}
 */
function buildTxtRecord(string $name, string $value): array
{
    return [
        'host' => requireValidRecordName($name),
        'txt' => requireValidTxtValue($value),
    ];
}

/**
 * Build the additive 20i DNS update payload for one TXT record.
 *
 * @return array<string,mixed>
 */
function buildAddTxtRecordPayload(string $name, string $value): array
{
    return [
        'conflictPolicy' => 'reject',
        'insertPolicy' => 'append',
        'new' => [
            'AAAA' => [],
            'A' => [],
            'CNAME' => [],
            'MX' => [],
            'TXT' => [
                buildTxtRecord($name, $value),
            ],
            'SRV' => [],
        ],
        'delete' => [],
    ];
}

/**
 * Build the fully qualified owner name for a record.
 */
function buildRecordFqdn(string $domain, string $recordName): string
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    $recordName = requireValidRecordName($recordName);

    if ($domain === '') {
        throw new InvalidArgumentException('The domain name cannot be empty.');
    }

    if ($recordName === '@') {
        return $domain;
    }

    if (
        $recordName === $domain
        || substr($recordName, -strlen('.' . $domain)) === '.' . $domain
    ) {
        return $recordName;
    }

    return $recordName . '.' . $domain;
}

/**
 * Determine whether an exact TXT value exists in a result set.
 *
 * @param array<int,string> $values
 */
function containsTxtValue(array $values, string $value): bool
{
    $value = normalizeTxtValue($value);

    foreach ($values as $existingValue) {
        if (
            is_string($existingValue)
            && txtValuesEqual($existingValue, $value)
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Query one authoritative nameserver for TXT records.
 *
 * The query is sent with recursion disabled. A valid response with no TXT
 * answers returns an empty array. NXDOMAIN also returns an empty array.
 *
 * @return array<int,string>
 */
function queryAuthoritativeTxtRecords(
    string $nameserver,
    string $fqdn,
    int $timeoutSeconds = DEFAULT_DNS_TIMEOUT_SECONDS
): array {
    $fqdn = strtolower(rtrim(trim($fqdn), '.'));

    if ($fqdn === '') {
        throw new InvalidArgumentException('The DNS query name cannot be empty.');
    }

    if ($timeoutSeconds < 1) {
        throw new InvalidArgumentException(
            'The DNS timeout must be at least one second.'
        );
    }

    $transactionId = random_int(0, 65535);
    $query = buildDnsQueryPacket(
        $transactionId,
        $fqdn,
        DNS_TYPE_TXT,
        DNS_CLASS_IN
    );

    $response = sendUdpDnsQuery(
        $nameserver,
        $query,
        $timeoutSeconds
    );

    $header = parseDnsHeader($response);
    validateDnsResponseHeader($header, $transactionId, $nameserver);

    if (($header['flags'] & DNS_FLAG_TC) !== 0) {
        $response = sendTcpDnsQuery(
            $nameserver,
            $query,
            $timeoutSeconds
        );

        $header = parseDnsHeader($response);
        validateDnsResponseHeader($header, $transactionId, $nameserver);
    }

    $rcode = $header['flags'] & DNS_RCODE_MASK;

    if ($rcode === DNS_RCODE_NXDOMAIN) {
        return [];
    }

    if ($rcode !== DNS_RCODE_NOERROR) {
        throw new RuntimeException(
            "DNS server '{$nameserver}' returned response code {$rcode}."
        );
    }

    return parseTxtAnswers($response, $header);
}

/**
 * Query StackDNS for TXT records at a requested record name.
 *
 * Nameservers are tried in order until one returns a valid authoritative
 * response.
 *
 * @param array<int,string> $nameservers
 * @return array<int,string>
 */
function getStackDnsTxtRecords(
    string $domain,
    string $recordName,
    array $nameservers = DEFAULT_STACKDNS_NAMESERVERS,
    int $timeoutSeconds = DEFAULT_DNS_TIMEOUT_SECONDS
): array {
    $fqdn = buildRecordFqdn($domain, $recordName);

    if ($nameservers === []) {
        throw new InvalidArgumentException(
            'At least one authoritative nameserver must be provided.'
        );
    }

    $errors = [];

    foreach ($nameservers as $nameserver) {
        if (!is_string($nameserver) || trim($nameserver) === '') {
            continue;
        }

        try {
            return queryAuthoritativeTxtRecords(
                trim($nameserver),
                $fqdn,
                $timeoutSeconds
            );
        } catch (\Throwable $exception) {
            $errors[] = trim($nameserver) . ': ' . $exception->getMessage();
        }
    }

    throw new RuntimeException(
        'Unable to retrieve authoritative TXT records. '
        . implode(' | ', $errors)
    );
}

/**
 * Determine whether the requested TXT record already exists in StackDNS.
 *
 * @param array<int,string> $nameservers
 */
function stackDnsTxtRecordExists(
    string $domain,
    string $recordName,
    string $value,
    array $nameservers = DEFAULT_STACKDNS_NAMESERVERS,
    int $timeoutSeconds = DEFAULT_DNS_TIMEOUT_SECONDS
): bool {
    return containsTxtValue(
        getStackDnsTxtRecords(
            $domain,
            $recordName,
            $nameservers,
            $timeoutSeconds
        ),
        requireValidTxtValue($value)
    );
}

/**
 * Build a DNS query packet with recursion disabled.
 */
function buildDnsQueryPacket(
    int $transactionId,
    string $fqdn,
    int $recordType,
    int $recordClass
): string {
    if ($transactionId < 0 || $transactionId > 65535) {
        throw new InvalidArgumentException(
            'The DNS transaction ID is out of range.'
        );
    }

    $header = pack(
        'nnnnnn',
        $transactionId,
        0,
        1,
        0,
        0,
        0
    );

    return $header
        . encodeDnsName($fqdn)
        . pack('nn', $recordType, $recordClass);
}

/**
 * Encode a domain name in DNS wire format.
 */
function encodeDnsName(string $name): string
{
    $name = rtrim(trim($name), '.');

    if ($name === '') {
        return "\0";
    }

    if (strlen($name) > 253) {
        throw new InvalidArgumentException(
            "DNS name '{$name}' exceeds the maximum length."
        );
    }

    $encoded = '';

    foreach (explode('.', $name) as $label) {
        $length = strlen($label);

        if ($length < 1 || $length > 63) {
            throw new InvalidArgumentException(
                "DNS label '{$label}' has an invalid length."
            );
        }

        $encoded .= chr($length) . $label;
    }

    return $encoded . "\0";
}

/**
 * Send a DNS query over UDP.
 */
function sendUdpDnsQuery(
    string $nameserver,
    string $query,
    int $timeoutSeconds
): string {
    $target = formatSocketTarget(
        'udp',
        $nameserver,
        DEFAULT_DNS_PORT
    );

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        $timeoutSeconds,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            "Unable to connect to DNS server '{$nameserver}' over UDP: "
            . ($errstr !== '' ? $errstr : "error {$errno}")
        );
    }

    stream_set_timeout($socket, $timeoutSeconds);

    $written = fwrite($socket, $query);

    if ($written === false || $written !== strlen($query)) {
        fclose($socket);

        throw new RuntimeException(
            "Unable to send the complete DNS query to '{$nameserver}'."
        );
    }

    $response = fread($socket, 65535);
    $metadata = stream_get_meta_data($socket);
    fclose($socket);

    if (!is_string($response) || $response === '') {
        if (!empty($metadata['timed_out'])) {
            throw new RuntimeException(
                "DNS query to '{$nameserver}' timed out."
            );
        }

        throw new RuntimeException(
            "DNS server '{$nameserver}' returned an empty UDP response."
        );
    }

    return $response;
}

/**
 * Send a DNS query over TCP.
 */
function sendTcpDnsQuery(
    string $nameserver,
    string $query,
    int $timeoutSeconds
): string {
    $target = formatSocketTarget(
        'tcp',
        $nameserver,
        DEFAULT_DNS_PORT
    );

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        $timeoutSeconds,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            "Unable to connect to DNS server '{$nameserver}' over TCP: "
            . ($errstr !== '' ? $errstr : "error {$errno}")
        );
    }

    stream_set_timeout($socket, $timeoutSeconds);

    $framedQuery = pack('n', strlen($query)) . $query;
    $written = fwrite($socket, $framedQuery);

    if ($written === false || $written !== strlen($framedQuery)) {
        fclose($socket);

        throw new RuntimeException(
            "Unable to send the complete TCP DNS query to '{$nameserver}'."
        );
    }

    $lengthBytes = readExact($socket, 2, $nameserver);
    $lengthData = unpack('nlength', $lengthBytes);

    if (!is_array($lengthData) || !isset($lengthData['length'])) {
        fclose($socket);

        throw new RuntimeException(
            "DNS server '{$nameserver}' returned an invalid TCP length prefix."
        );
    }

    $response = readExact(
        $socket,
        (int) $lengthData['length'],
        $nameserver
    );

    fclose($socket);

    return $response;
}

/**
 * Read an exact number of bytes from a stream.
 *
 * @param resource $socket
 */
function readExact($socket, int $length, string $nameserver): string
{
    $data = '';

    while (strlen($data) < $length) {
        $chunk = fread(
            $socket,
            $length - strlen($data)
        );

        if ($chunk === false || $chunk === '') {
            $metadata = stream_get_meta_data($socket);

            if (!empty($metadata['timed_out'])) {
                throw new RuntimeException(
                    "DNS query to '{$nameserver}' timed out."
                );
            }

            throw new RuntimeException(
                "DNS server '{$nameserver}' closed the TCP connection early."
            );
        }

        $data .= $chunk;
    }

    return $data;
}

/**
 * Format a socket target.
 */
function formatSocketTarget(
    string $scheme,
    string $host,
    int $port
): string {
    $host = trim($host);

    if ($host === '') {
        throw new InvalidArgumentException(
            'The DNS nameserver cannot be empty.'
        );
    }

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $host = '[' . $host . ']';
    }

    return "{$scheme}://{$host}:{$port}";
}

/**
 * Parse the 12-byte DNS response header.
 *
 * @return array{id:int,flags:int,qdcount:int,ancount:int,nscount:int,arcount:int}
 */
function parseDnsHeader(string $response): array
{
    if (strlen($response) < 12) {
        throw new RuntimeException(
            'The DNS server returned a response shorter than the DNS header.'
        );
    }

    $header = unpack(
        'nid/nflags/nqdcount/nancount/nnscount/narcount',
        substr($response, 0, 12)
    );

    if (!is_array($header)) {
        throw new RuntimeException(
            'Unable to parse the DNS response header.'
        );
    }

    return [
        'id' => (int) $header['id'],
        'flags' => (int) $header['flags'],
        'qdcount' => (int) $header['qdcount'],
        'ancount' => (int) $header['ancount'],
        'nscount' => (int) $header['nscount'],
        'arcount' => (int) $header['arcount'],
    ];
}

/**
 * Validate the common properties of a DNS response.
 *
 * @param array{id:int,flags:int,qdcount:int,ancount:int,nscount:int,arcount:int} $header
 */
function validateDnsResponseHeader(
    array $header,
    int $transactionId,
    string $nameserver
): void {
    if ($header['id'] !== $transactionId) {
        throw new RuntimeException(
            "DNS server '{$nameserver}' returned a mismatched transaction ID."
        );
    }

    if (($header['flags'] & DNS_FLAG_QR) === 0) {
        throw new RuntimeException(
            "DNS server '{$nameserver}' returned a packet that is not a response."
        );
    }

    if (($header['flags'] & DNS_FLAG_AA) === 0) {
        throw new RuntimeException(
            "DNS server '{$nameserver}' did not return an authoritative answer."
        );
    }
}

/**
 * Parse TXT answers from a DNS response packet.
 *
 * @param array{id:int,flags:int,qdcount:int,ancount:int,nscount:int,arcount:int} $header
 * @return array<int,string>
 */
function parseTxtAnswers(string $response, array $header): array
{
    $offset = 12;
    $responseLength = strlen($response);

    for ($index = 0; $index < $header['qdcount']; $index++) {
        skipDnsName($response, $offset);
        requirePacketBytes($responseLength, $offset, 4);
        $offset += 4;
    }

    $records = [];

    for ($index = 0; $index < $header['ancount']; $index++) {
        skipDnsName($response, $offset);
        requirePacketBytes($responseLength, $offset, 10);

        $recordHeader = unpack(
            'ntype/nclass/Nttl/ndlength',
            substr($response, $offset, 10)
        );

        if (!is_array($recordHeader)) {
            throw new RuntimeException(
                'Unable to parse a DNS resource-record header.'
            );
        }

        $offset += 10;

        $dataLength = (int) $recordHeader['dlength'];
        requirePacketBytes(
            $responseLength,
            $offset,
            $dataLength
        );

        if (
            (int) $recordHeader['type'] === DNS_TYPE_TXT
            && (int) $recordHeader['class'] === DNS_CLASS_IN
        ) {
            $records[] = parseTxtRdata(
                substr($response, $offset, $dataLength)
            );
        }

        $offset += $dataLength;
    }

    return $records;
}

/**
 * Parse one TXT RDATA field, concatenating all character-string chunks.
 */
function parseTxtRdata(string $data): string
{
    $offset = 0;
    $length = strlen($data);
    $value = '';

    while ($offset < $length) {
        $chunkLength = ord($data[$offset]);
        $offset++;

        if ($offset + $chunkLength > $length) {
            throw new RuntimeException(
                'The DNS server returned malformed TXT record data.'
            );
        }

        $value .= substr(
            $data,
            $offset,
            $chunkLength
        );

        $offset += $chunkLength;
    }

    return $value;
}

/**
 * Advance an offset past a possibly compressed DNS name.
 */
function skipDnsName(string $packet, int &$offset): void
{
    $packetLength = strlen($packet);
    $labelsSeen = 0;

    while (true) {
        requirePacketBytes($packetLength, $offset, 1);

        $length = ord($packet[$offset]);

        if (($length & 0xC0) === 0xC0) {
            requirePacketBytes($packetLength, $offset, 2);
            $offset += 2;
            return;
        }

        if (($length & 0xC0) !== 0) {
            throw new RuntimeException(
                'The DNS response contains an unsupported label encoding.'
            );
        }

        $offset++;

        if ($length === 0) {
            return;
        }

        if ($length > 63) {
            throw new RuntimeException(
                'The DNS response contains an invalid label length.'
            );
        }

        requirePacketBytes(
            $packetLength,
            $offset,
            $length
        );

        $offset += $length;
        $labelsSeen++;

        if ($labelsSeen > 127) {
            throw new RuntimeException(
                'The DNS response contains too many labels.'
            );
        }
    }
}

/**
 * Ensure that a packet contains the requested byte range.
 */
function requirePacketBytes(
    int $packetLength,
    int $offset,
    int $requiredLength
): void {
    if (
        $offset < 0
        || $requiredLength < 0
        || $offset + $requiredLength > $packetLength
    ) {
        throw new RuntimeException(
            'The DNS response ended unexpectedly.'
        );
    }
}

/**
 * Map a record type name (A, AAAA, CNAME, MX, NS, SOA, TXT, PTR) to its code.
 */
function recordTypeCode(string $type): int
{
    $type = strtoupper(trim($type));

    $map = [
        'A' => DNS_TYPE_A,
        'AAAA' => DNS_TYPE_AAAA,
        'CNAME' => DNS_TYPE_CNAME,
        'MX' => DNS_TYPE_MX,
        'NS' => DNS_TYPE_NS,
        'SOA' => DNS_TYPE_SOA,
        'TXT' => DNS_TYPE_TXT,
        'PTR' => DNS_TYPE_PTR,
        'SRV' => DNS_TYPE_SRV,
    ];

    if (!isset($map[$type])) {
        throw new InvalidArgumentException(
            "Unsupported DNS record type '{$type}'."
        );
    }

    return $map[$type];
}

/**
 * Map a numeric record type code to a display name.
 */
function recordTypeName(int $code): string
{
    $map = [
        DNS_TYPE_A => 'A',
        DNS_TYPE_AAAA => 'AAAA',
        DNS_TYPE_CNAME => 'CNAME',
        DNS_TYPE_MX => 'MX',
        DNS_TYPE_NS => 'NS',
        DNS_TYPE_SOA => 'SOA',
        DNS_TYPE_TXT => 'TXT',
        DNS_TYPE_PTR => 'PTR',
        DNS_TYPE_SRV => 'SRV',
    ];

    return $map[$code] ?? 'TYPE' . $code;
}

/**
 * Decode a possibly compressed DNS name starting at the given offset.
 *
 * The offset is advanced past the name the same way skipDnsName() does:
 * when a compression pointer terminates the name, the offset stops right
 * past the pointer while the pointer target is resolved separately.
 */
function decodeDnsName(string $packet, int &$offset, int $depth = 0): string
{
    if ($depth > 32) {
        throw new RuntimeException(
            'The DNS response contains a cyclic compression pointer.'
        );
    }

    $packetLength = strlen($packet);
    $labels = [];
    $labelsSeen = 0;

    while (true) {
        requirePacketBytes($packetLength, $offset, 1);

        $length = ord($packet[$offset]);

        if (($length & 0xC0) === 0xC0) {
            requirePacketBytes($packetLength, $offset, 2);
            $pointerOffset = ($length & 0x3F) << 8 | ord($packet[$offset + 1]);
            $offset += 2;

            $tail = decodeDnsName($packet, $pointerOffset, $depth + 1);

            if ($tail !== '') {
                $labels[] = $tail;
            }

            return implode('.', $labels);
        }

        if (($length & 0xC0) !== 0) {
            throw new RuntimeException(
                'The DNS response contains an unsupported label encoding.'
            );
        }

        $offset++;

        if ($length === 0) {
            return implode('.', $labels);
        }

        if ($length > 63) {
            throw new RuntimeException(
                'The DNS response contains an invalid label length.'
            );
        }

        requirePacketBytes(
            $packetLength,
            $offset,
            $length
        );

        $labels[] = substr($packet, $offset, $length);
        $offset += $length;
        $labelsSeen++;

        if ($labelsSeen > 127) {
            throw new RuntimeException(
                'The DNS response contains too many labels.'
            );
        }
    }
}

/**
 * Parse every answer-section resource record from a DNS response.
 *
 * RDATA is decoded for common types. Other types surface as lowercase hex.
 *
 * @return array<int,array{owner:string,type:string,ttl:int,rdata:string}>
 */
function parseResourceRecords(string $response, array $header): array
{
    $offset = 12;
    $responseLength = strlen($response);

    for ($index = 0; $index < $header['qdcount']; $index++) {
        skipDnsName($response, $offset);
        requirePacketBytes($responseLength, $offset, 4);
        $offset += 4;
    }

    $records = [];

    for ($index = 0; $index < $header['ancount']; $index++) {
        $owner = decodeDnsName($response, $offset);
        requirePacketBytes($responseLength, $offset, 10);

        $recordHeader = unpack(
            'ntype/nclass/Nttl/ndlength',
            substr($response, $offset, 10)
        );

        if (!is_array($recordHeader)) {
            throw new RuntimeException(
                'Unable to parse a DNS resource-record header.'
            );
        }

        $offset += 10;

        $type = (int) $recordHeader['type'];
        $dataLength = (int) $recordHeader['dlength'];
        requirePacketBytes($responseLength, $offset, $dataLength);

        $rdataOffset = $offset;
        $offset += $dataLength;

        if ((int) $recordHeader['class'] !== DNS_CLASS_IN) {
            continue;
        }

        switch ($type) {
            case DNS_TYPE_A:
                if ($dataLength !== 4) {
                    continue 2;
                }
                $rdata = implode(
                    '.',
                    array_map('ord', str_split(
                        substr($response, $rdataOffset, 4)
                    ))
                );
                break;

            case DNS_TYPE_AAAA:
                if ($dataLength !== 16) {
                    continue 2;
                }
                $rdata = inet_ntop(substr($response, $rdataOffset, 16));
                if ($rdata === false) {
                    continue 2;
                }
                break;

            case DNS_TYPE_CNAME:
            case DNS_TYPE_NS:
            case DNS_TYPE_PTR:
                $targetOffset = $rdataOffset;
                $rdata = decodeDnsName($response, $targetOffset);
                break;

            case DNS_TYPE_MX:
                if ($dataLength < 3) {
                    continue 2;
                }
                $preference = unpack(
                    'n',
                    substr($response, $rdataOffset, 2)
                )[1];
                $exchangeOffset = $rdataOffset + 2;
                $exchange = decodeDnsName($response, $exchangeOffset);
                $rdata = $preference . ' ' . $exchange;
                break;

            case DNS_TYPE_SOA:
                $mnameOffset = $rdataOffset;
                $mname = decodeDnsName($response, $mnameOffset);
                $rname = decodeDnsName($response, $mnameOffset);
                if ($mnameOffset + 20 > $rdataOffset + $dataLength) {
                    continue 2;
                }
                $soaFields = unpack(
                    'Nserial/Nrefresh/Nretry/Nexpire/Nminimum',
                    substr($response, $mnameOffset, 20)
                );
                $rdata = $mname . ' ' . $rname . ' '
                    . $soaFields['serial'] . ' ' . $soaFields['refresh'] . ' '
                    . $soaFields['retry'] . ' ' . $soaFields['expire'] . ' '
                    . $soaFields['minimum'];
                break;

            case DNS_TYPE_TXT:
                $rdata = parseTxtRdata(
                    substr($response, $rdataOffset, $dataLength)
                );
                break;

            case DNS_TYPE_SRV:
                if ($dataLength < 7) {
                    continue 2;
                }
                $srvFields = unpack(
                    'npriority/nweight/nport',
                    substr($response, $rdataOffset, 6)
                );
                $srvTargetOffset = $rdataOffset + 6;
                $srvTarget = decodeDnsName($response, $srvTargetOffset);
                $rdata = $srvFields['priority'] . ' '
                    . $srvFields['weight'] . ' '
                    . $srvFields['port'] . ' '
                    . $srvTarget;
                break;

            default:
                $rdata = bin2hex(substr($response, $rdataOffset, $dataLength));
                break;
        }

        $records[] = [
            'owner' => $owner,
            'type' => recordTypeName($type),
            'ttl' => (int) $recordHeader['ttl'],
            'rdata' => $rdata,
        ];
    }

    return $records;
}

/**
 * Query one authoritative nameserver for records of a given type.
 *
 * A valid response with no matching answers, or NXDOMAIN, returns an
 * empty array. Truncated responses retry over TCP like the TXT path.
 *
 * @return array<int,array{owner:string,type:string,ttl:int,rdata:string}>
 */
function queryAuthoritativeRecords(
    string $nameserver,
    string $fqdn,
    int $recordType,
    int $timeoutSeconds = DEFAULT_DNS_TIMEOUT_SECONDS
): array {
    $fqdn = strtolower(rtrim(trim($fqdn), '.'));

    if ($fqdn === '') {
        throw new InvalidArgumentException('The DNS query name cannot be empty.');
    }

    if ($timeoutSeconds < 1) {
        throw new InvalidArgumentException(
            'The DNS timeout must be at least one second.'
        );
    }

    $transactionId = random_int(0, 65535);
    $query = buildDnsQueryPacket(
        $transactionId,
        $fqdn,
        $recordType,
        DNS_CLASS_IN
    );

    $response = sendUdpDnsQuery(
        $nameserver,
        $query,
        $timeoutSeconds
    );

    $header = parseDnsHeader($response);
    validateDnsResponseHeader($header, $transactionId, $nameserver);

    if (($header['flags'] & DNS_FLAG_TC) !== 0) {
        $response = sendTcpDnsQuery(
            $nameserver,
            $query,
            $timeoutSeconds
        );

        $header = parseDnsHeader($response);
        validateDnsResponseHeader($header, $transactionId, $nameserver);
    }

    $rcode = $header['flags'] & DNS_RCODE_MASK;

    if ($rcode === DNS_RCODE_NXDOMAIN) {
        return [];
    }

    if ($rcode !== DNS_RCODE_NOERROR) {
        throw new RuntimeException(
            "DNS server '{$nameserver}' returned response code {$rcode}."
        );
    }

    $records = parseResourceRecords($response, $header);
    $typeName = recordTypeName($recordType);

    return array_values(array_filter(
        $records,
        static function (array $record) use ($typeName, $recordType): bool {
            return $record['type'] === $typeName
                || (strpos($record['type'], 'TYPE') === 0
                    && $record['type'] === 'TYPE' . $recordType);
        }
    ));
}

/**
 * Query StackDNS for records of a given type at a fully qualified name.
 *
 * Nameservers are tried in order until one returns a valid authoritative
 * response.
 *
 * @param array<int,string> $nameservers
 * @return array<int,array{owner:string,type:string,ttl:int,rdata:string}>
 */
function getStackDnsRecords(
    string $fqdn,
    int $recordType,
    array $nameservers = DEFAULT_STACKDNS_NAMESERVERS,
    int $timeoutSeconds = DEFAULT_DNS_TIMEOUT_SECONDS
): array {
    $fqdn = strtolower(rtrim(trim($fqdn), '.'));

    if ($fqdn === '') {
        throw new InvalidArgumentException('The DNS query name cannot be empty.');
    }

    if ($nameservers === []) {
        throw new InvalidArgumentException(
            'At least one authoritative nameserver must be provided.'
        );
    }

    $errors = [];

    foreach ($nameservers as $nameserver) {
        if (!is_string($nameserver) || trim($nameserver) === '') {
            continue;
        }

        try {
            return queryAuthoritativeRecords(
                trim($nameserver),
                $fqdn,
                $recordType,
                $timeoutSeconds
            );
        } catch (\Throwable $exception) {
            $errors[] = trim($nameserver) . ': ' . $exception->getMessage();
        }
    }

    throw new RuntimeException(
        'Unable to retrieve authoritative DNS records. '
        . implode(' | ', $errors)
    );
}
