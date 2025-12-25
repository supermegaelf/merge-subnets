#!/usr/bin/env php
<?php
/**
 * Script to merge overlapping and adjacent subnets to reduce the total number of entries.
 */

/**
 * Normalize subnet: set last octet to 0 and limit prefix to /24 maximum.
 *
 * @param string $subnet Subnet string in CIDR notation
 * @return array Array with 'ip' and 'prefix' keys
 */
function normalize_subnet($subnet) {
    $parts = explode('/', trim($subnet));
    if (count($parts) !== 2) {
        throw new InvalidArgumentException("Invalid subnet format: $subnet");
    }
    
    $ip = $parts[0];
    $prefix = (int)$parts[1];
    
    // Convert IP to long integer
    $ip_long = ip2long($ip);
    if ($ip_long === false) {
        throw new InvalidArgumentException("Invalid IP address: $ip");
    }
    
    // Clear the last octet (set to 0)
    $ip_long = ($ip_long >> 8) << 8;
    
    // Convert back to IP string
    $new_ip = long2ip($ip_long);
    
    // If prefix length is greater than 24, set it to 24
    $new_prefix = min($prefix, 24);
    
    // Apply the prefix mask to ensure network address
    $mask = -1 << (32 - $new_prefix);
    $network_long = $ip_long & $mask;
    $network_ip = long2ip($network_long);
    
    return [
        'ip' => $network_ip,
        'prefix' => $new_prefix,
        'ip_long' => $network_long,
        'cidr' => "$network_ip/$new_prefix"
    ];
}

/**
 * Check if two networks can be merged.
 *
 * @param array $net1 First network
 * @param array $net2 Second network
 * @return bool True if networks can be merged
 */
function can_merge($net1, $net2) {
    // Networks must have the same prefix length
    if ($net1['prefix'] !== $net2['prefix']) {
        return false;
    }
    
    $prefix = $net1['prefix'];
    
    // Calculate the size of the network
    $size = 1 << (32 - $prefix);
    
    // Check if net2 immediately follows net1
    return ($net1['ip_long'] + $size) === $net2['ip_long'];
}

/**
 * Merge two adjacent networks into a larger network.
 *
 * @param array $net1 First network
 * @param array $net2 Second network
 * @return array Merged network
 */
function merge_two_networks($net1, $net2) {
    $new_prefix = $net1['prefix'] - 1;
    $mask = -1 << (32 - $new_prefix);
    $network_long = $net1['ip_long'] & $mask;
    $network_ip = long2ip($network_long);
    
    return [
        'ip' => $network_ip,
        'prefix' => $new_prefix,
        'ip_long' => $network_long,
        'cidr' => "$network_ip/$new_prefix"
    ];
}

/**
 * Check if one network contains another.
 *
 * @param array $net1 First network
 * @param array $net2 Second network
 * @return bool True if net1 contains net2
 */
function network_contains($net1, $net2) {
    // net1 contains net2 if net1 has smaller prefix (larger network)
    // and net2's address falls within net1's range
    if ($net1['prefix'] >= $net2['prefix']) {
        return false;
    }
    
    $mask1 = -1 << (32 - $net1['prefix']);
    $net1_base = $net1['ip_long'] & $mask1;
    $net2_base = $net2['ip_long'] & $mask1;
    
    return $net1_base === $net2_base;
}

/**
 * Merge overlapping and adjacent subnets.
 *
 * @param array $subnets List of subnet strings in CIDR notation
 * @return array List of merged subnets in CIDR notation
 */
function merge_subnets($subnets) {
    $networks = [];
    
    // Parse and normalize all subnets
    foreach ($subnets as $subnet) {
        try {
            $normalized = normalize_subnet($subnet);
            $networks[$normalized['cidr']] = $normalized;
        } catch (Exception $e) {
            echo "Warning: Skipping invalid subnet $subnet: " . $e->getMessage() . "\n";
        }
    }
    
    // Remove duplicates and get values
    $networks = array_values($networks);
    
    // Sort networks by IP address and prefix length
    usort($networks, function($a, $b) {
        if ($a['ip_long'] !== $b['ip_long']) {
            return $a['ip_long'] - $b['ip_long'];
        }
        return $a['prefix'] - $b['prefix'];
    });
    
    // Remove networks that are contained in other networks
    $filtered = [];
    for ($i = 0; $i < count($networks); $i++) {
        $contained = false;
        for ($j = 0; $j < count($networks); $j++) {
            if ($i !== $j && network_contains($networks[$j], $networks[$i])) {
                $contained = true;
                break;
            }
        }
        if (!$contained) {
            $filtered[] = $networks[$i];
        }
    }
    $networks = $filtered;
    
    // Iteratively merge adjacent networks with same prefix
    $changed = true;
    while ($changed) {
        $changed = false;
        
        // Sort again after merging
        usort($networks, function($a, $b) {
            if ($a['ip_long'] !== $b['ip_long']) {
                return $a['ip_long'] - $b['ip_long'];
            }
            return $a['prefix'] - $b['prefix'];
        });
        
        $merged = [];
        $skip_next = false;
        
        for ($i = 0; $i < count($networks); $i++) {
            if ($skip_next) {
                $skip_next = false;
                continue;
            }
            
            // Try to merge with next network
            if ($i < count($networks) - 1 && can_merge($networks[$i], $networks[$i + 1])) {
                $new_net = merge_two_networks($networks[$i], $networks[$i + 1]);
                // Check if the new network can be merged with the network before it
                if (count($merged) > 0 && can_merge($merged[count($merged) - 1], $new_net)) {
                    $merged[count($merged) - 1] = merge_two_networks($merged[count($merged) - 1], $new_net);
                } else {
                    $merged[] = $new_net;
                }
                $skip_next = true;
                $changed = true;
            } else {
                $merged[] = $networks[$i];
            }
        }
        
        $networks = $merged;
    }
    
    // Extract CIDR strings
    return array_map(function($net) {
        return $net['cidr'];
    }, $networks);
}

/**
 * Main function.
 */
function main($argc, $argv) {
    if ($argc !== 3) {
        echo "Usage: {$argv[0]} <input_file> <output_file>\n";
        echo "Merge overlapping and adjacent subnets to reduce the total number of entries.\n";
        exit(1);
    }
    
    $input_file = $argv[1];
    $output_file = $argv[2];
    
    // Read subnets from file
    echo "Reading subnets from $input_file...\n";
    if (!file_exists($input_file)) {
        echo "Error: Input file not found: $input_file\n";
        exit(1);
    }
    
    $content = file_get_contents($input_file);
    $lines = array_map('trim', explode("\n", $content));
    
    // Filter out empty lines and comments (lines starting with #)
    $subnets = array_filter($lines, function($line) {
        return $line !== '' && $line[0] !== '#';
    });
    
    echo "Original number of subnets: " . count($subnets) . "\n";
    
    // Merge subnets
    echo "Merging subnets...\n";
    $merged_subnets = merge_subnets($subnets);
    
    echo "Merged number of subnets: " . count($merged_subnets) . "\n";
    $reduction = count($subnets) - count($merged_subnets);
    $percent = count($subnets) > 0 ? round(100 * $reduction / count($subnets), 1) : 0;
    echo "Reduction: $reduction subnets ($percent%)\n";
    
    // Write merged subnets to file
    echo "Writing merged subnets to $output_file...\n";
    file_put_contents($output_file, implode("\n", $merged_subnets) . "\n");
    
    echo "Done!\n";
}

main($argc, $argv);
