<?php

declare(strict_types=1);

// 107 long-haul destinations, tier 2 of 2 (docs/BUSINESS-LOGIC.md §11).
/** @return array{climates: array<string, list<int>>, origins: list<array{string, string, string, string, string, float, float}>, destinations: list<array{string, string, string, string, string, float, float, string, list<string>}>} */

return [

    // Jan .. Dec, 1-5.
    'climates' => [
        // Singapore, Malé, the equator.
        'equatorial' => [5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5],

        // Serves both hemispheres: monsoon north (Bangkok), winter south (Rio).
        'tropical-seasonal' => [5, 5, 5, 5, 4, 4, 4, 4, 4, 5, 5, 5],

        // Northern Indochina's cool season: Hanoi, Chiang Mai.
        'tropical-north' => [3, 4, 4, 5, 5, 4, 4, 4, 4, 4, 4, 3],

        // Warm all year; 4 in Sep/Oct for hurricane season.
        'caribbean' => [5, 5, 5, 5, 5, 5, 5, 5, 4, 4, 5, 5],

        // Florida and Baja.
        'florida' => [4, 4, 4, 5, 5, 5, 5, 5, 5, 5, 4, 4],

        // Inland: Las Vegas, Delhi.
        'desert' => [2, 3, 4, 5, 5, 5, 5, 5, 5, 4, 3, 2],

        // The Gulf — 5 is a ceiling, not a recommendation (docs/BUSINESS-LOGIC.md §11).
        'gulf' => [4, 4, 5, 5, 5, 5, 5, 5, 5, 5, 5, 4],

        // Eastern Mediterranean, a month warmer at each end.
        'levant' => [2, 3, 3, 4, 5, 5, 5, 5, 5, 4, 3, 3],

        // Hong Kong, Taipei, Okinawa, New Orleans.
        'subtropical' => [3, 3, 4, 4, 5, 5, 5, 5, 5, 5, 4, 3],

        // Tokyo, Osaka, Shanghai.
        'east-asia' => [2, 2, 3, 4, 5, 5, 5, 5, 4, 4, 3, 2],

        // Southern California.
        'california' => [3, 3, 3, 4, 4, 4, 5, 5, 5, 4, 3, 3],

        // San Francisco, famously not California's.
        'pacific-mild' => [2, 2, 3, 3, 3, 3, 3, 4, 4, 4, 3, 2],

        // 1,500m up: Nairobi, Addis, Mexico City, Johannesburg.
        'highland' => [4, 4, 4, 4, 4, 3, 3, 3, 4, 4, 4, 4],

        // Higher again: Bogotá, Quito, Cusco.
        'andean' => [3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3],

        // Upside down: Cape Town, Sydney, Buenos Aires, Perth.
        'southern-med' => [5, 5, 4, 3, 2, 2, 2, 2, 3, 4, 4, 5],

        // Milder, no biting winter: São Paulo, Lima, Brisbane.
        'southern-subtropical' => [5, 5, 4, 4, 3, 3, 3, 3, 4, 4, 4, 5],

        // Auckland and Melbourne.
        'southern-oceanic' => [4, 4, 4, 3, 2, 2, 1, 2, 2, 3, 3, 4],

        // Windhoek, Victoria Falls.
        'southern-savanna' => [5, 5, 5, 4, 3, 3, 3, 4, 5, 5, 5, 5],

        // Queenstown: ski season is July.
        'southern-alpine' => [3, 3, 3, 2, 1, 1, 1, 1, 2, 2, 3, 3],
    ],

    // Empty — the owner's three origins live in european_destinations.php.
    'origins' => [],

    // [iata, airport name, city, country, country code, lat, lng, climate, vibes]
    'destinations' => [
        // North America
        ['JFK', 'John F. Kennedy', 'New York', 'United States', 'US', 40.6394, -73.7793, 'continental', ['city', 'culture', 'food', 'party']],
        ['BOS', 'Boston Logan', 'Boston', 'United States', 'US', 42.362, -71.0079, 'continental', ['city', 'culture']],
        ['IAD', 'Washington Dulles', 'Washington', 'United States', 'US', 38.9445, -77.4558, 'continental', ['culture', 'city']],
        ['ORD', 'O\'Hare', 'Chicago', 'United States', 'US', 41.9786, -87.9048, 'continental', ['city', 'food', 'culture']],
        ['YYZ', 'Toronto Pearson', 'Toronto', 'Canada', 'CA', 43.6759, -79.6294, 'continental', ['city', 'food']],
        ['YUL', 'Pierre Elliott Trudeau', 'Montréal', 'Canada', 'CA', 45.4678, -73.7423, 'continental', ['city', 'food', 'culture']],
        ['YVR', 'Vancouver', 'Vancouver', 'Canada', 'CA', 49.1939, -123.184, 'oceanic', ['city', 'nature']],
        ['SEA', 'Seattle–Tacoma', 'Seattle', 'United States', 'US', 47.4479, -122.31, 'oceanic', ['city', 'nature', 'food']],
        ['SFO', 'San Francisco', 'San Francisco', 'United States', 'US', 37.6198, -122.375, 'pacific-mild', ['city', 'food', 'culture']],
        ['LAX', 'Los Angeles', 'Los Angeles', 'United States', 'US', 33.9425, -118.408, 'california', ['city', 'beach', 'sunny']],
        ['SAN', 'San Diego', 'San Diego', 'United States', 'US', 32.7336, -117.19, 'california', ['beach', 'sunny', 'nature']],
        ['LAS', 'Harry Reid', 'Las Vegas', 'United States', 'US', 36.0834, -115.152, 'desert', ['party', 'city', 'sunny']],
        ['DEN', 'Denver', 'Denver', 'United States', 'US', 39.86, -104.674, 'continental', ['ski', 'nature']],
        ['SLC', 'Salt Lake City', 'Salt Lake City', 'United States', 'US', 40.7889, -111.98, 'continental', ['ski', 'nature']],
        ['YYC', 'Calgary', 'Calgary', 'Canada', 'CA', 51.1188, -114.01, 'nordic', ['ski', 'nature']],
        ['MIA', 'Miami', 'Miami', 'United States', 'US', 25.796, -80.2898, 'florida', ['beach', 'party', 'sunny', 'city']],
        ['MCO', 'Orlando', 'Orlando', 'United States', 'US', 28.4294, -81.309, 'florida', ['sunny', 'city']],
        ['MSY', 'Louis Armstrong', 'New Orleans', 'United States', 'US', 29.9934, -90.2647, 'subtropical', ['food', 'culture', 'party']],
        ['HNL', 'Daniel K. Inouye', 'Honolulu', 'United States', 'US', 21.3184, -157.926, 'equatorial', ['beach', 'islands', 'sunny', 'nature']],
        // Mexico, Central America and the Caribbean
        ['MEX', 'Benito Juárez', 'Mexico City', 'Mexico', 'MX', 19.4358, -99.0703, 'highland', ['city', 'culture', 'food']],
        ['CUN', 'Cancún', 'Cancún', 'Mexico', 'MX', 21.0408, -86.8735, 'caribbean', ['beach', 'party', 'sunny']],
        ['SJD', 'Los Cabos', 'Los Cabos', 'Mexico', 'MX', 23.1519, -109.721, 'florida', ['beach', 'sunny']],
        ['HAV', 'José Martí', 'Havana', 'Cuba', 'CU', 22.9892, -82.4091, 'caribbean', ['culture', 'city', 'beach']],
        ['PUJ', 'Punta Cana', 'Punta Cana', 'Dominican Republic', 'DO', 18.5671, -68.3646, 'caribbean', ['beach', 'sunny', 'party']],
        ['MBJ', 'Sangster', 'Montego Bay', 'Jamaica', 'JM', 18.5034, -77.9132, 'caribbean', ['beach', 'sunny']],
        ['NAS', 'Lynden Pindling', 'Nassau', 'Bahamas', 'BS', 25.039, -77.4662, 'caribbean', ['beach', 'islands', 'sunny']],
        ['SJU', 'Luis Muñoz Marín', 'San Juan', 'Puerto Rico', 'PR', 18.4394, -66.0018, 'caribbean', ['beach', 'city', 'sunny']],
        ['BGI', 'Grantley Adams', 'Bridgetown', 'Barbados', 'BB', 13.0747, -59.491, 'caribbean', ['beach', 'islands', 'sunny']],
        ['AUA', 'Queen Beatrix', 'Oranjestad', 'Aruba', 'AW', 12.5011, -70.0143, 'caribbean', ['beach', 'islands', 'sunny']],
        ['CUR', 'Hato', 'Willemstad', 'Curaçao', 'CW', 12.1889, -68.9598, 'caribbean', ['beach', 'islands', 'sunny']],
        ['SXM', 'Princess Juliana', 'Sint Maarten', 'Sint Maarten', 'SX', 18.041, -63.1089, 'caribbean', ['beach', 'islands', 'sunny']],
        ['SJO', 'Juan Santamaría', 'San José', 'Costa Rica', 'CR', 9.99386, -84.2088, 'highland', ['nature', 'culture']],
        ['PTY', 'Tocumen', 'Panama City', 'Panama', 'PA', 9.07136, -79.3835, 'equatorial', ['city', 'beach']],
        ['BZE', 'Philip S. W. Goldson', 'Belize City', 'Belize', 'BZ', 17.54, -88.3036, 'tropical-seasonal', ['beach', 'islands', 'nature']],
        // South America
        ['GRU', 'Guarulhos', 'São Paulo', 'Brazil', 'BR', -23.4313, -46.47, 'southern-subtropical', ['city', 'food']],
        ['GIG', 'Galeão', 'Rio de Janeiro', 'Brazil', 'BR', -22.81, -43.2506, 'tropical-seasonal', ['beach', 'city', 'party', 'sunny']],
        ['SSA', 'Luís Eduardo Magalhães', 'Salvador', 'Brazil', 'BR', -12.9086, -38.3225, 'tropical-seasonal', ['beach', 'culture', 'party']],
        ['REC', 'Guararapes', 'Recife', 'Brazil', 'BR', -8.12747, -34.923, 'tropical-seasonal', ['beach', 'sunny']],
        ['EZE', 'Ministro Pistarini', 'Buenos Aires', 'Argentina', 'AR', -34.8222, -58.5358, 'southern-med', ['city', 'food', 'culture', 'party']],
        ['SCL', 'Arturo Merino Benítez', 'Santiago', 'Chile', 'CL', -33.393, -70.7858, 'southern-med', ['city', 'nature', 'ski']],
        ['LIM', 'Jorge Chávez', 'Lima', 'Peru', 'PE', -12.0219, -77.1143, 'southern-subtropical', ['city', 'food', 'culture']],
        ['CUZ', 'Alejandro Velasco Astete', 'Cusco', 'Peru', 'PE', -13.5357, -71.9388, 'andean', ['culture', 'nature']],
        ['BOG', 'El Dorado', 'Bogotá', 'Colombia', 'CO', 4.70159, -74.1469, 'andean', ['city', 'culture']],
        ['UIO', 'Mariscal Sucre', 'Quito', 'Ecuador', 'EC', -0.125399, -78.3543, 'andean', ['culture', 'nature']],
        ['MDE', 'José María Córdova', 'Medellín', 'Colombia', 'CO', 6.16454, -75.4231, 'highland', ['city', 'nature']],
        ['CTG', 'Rafael Núñez', 'Cartagena', 'Colombia', 'CO', 10.4424, -75.513, 'caribbean', ['beach', 'culture', 'sunny']],
        ['GPS', 'Seymour (Baltra)', 'Galápagos', 'Ecuador', 'EC', -0.453758, -90.2659, 'equatorial', ['nature', 'islands']],
        // South-East Asia
        ['BKK', 'Suvarnabhumi', 'Bangkok', 'Thailand', 'TH', 13.6811, 100.747, 'tropical-seasonal', ['city', 'food', 'party', 'culture']],
        ['HKT', 'Phuket', 'Phuket', 'Thailand', 'TH', 8.11326, 98.3174, 'tropical-seasonal', ['beach', 'islands', 'party', 'sunny']],
        ['USM', 'Samui', 'Ko Samui', 'Thailand', 'TH', 9.54779, 100.062, 'tropical-seasonal', ['beach', 'islands', 'sunny']],
        ['CNX', 'Chiang Mai', 'Chiang Mai', 'Thailand', 'TH', 18.7668, 98.9626, 'tropical-north', ['culture', 'food', 'nature']],
        ['SIN', 'Changi', 'Singapore', 'Singapore', 'SG', 1.35019, 103.994, 'equatorial', ['city', 'food']],
        ['KUL', 'Kuala Lumpur', 'Kuala Lumpur', 'Malaysia', 'MY', 2.74558, 101.71, 'equatorial', ['city', 'food']],
        ['DPS', 'Ngurah Rai', 'Bali', 'Indonesia', 'ID', -8.74841, 115.167, 'tropical-seasonal', ['beach', 'islands', 'nature', 'culture']],
        ['CGK', 'Soekarno–Hatta', 'Jakarta', 'Indonesia', 'ID', -6.12557, 106.656, 'equatorial', ['city', 'food']],
        ['SGN', 'Tan Son Nhat', 'Ho Chi Minh City', 'Vietnam', 'VN', 10.8188, 106.652, 'tropical-seasonal', ['city', 'food', 'culture']],
        ['HAN', 'Noi Bai', 'Hanoi', 'Vietnam', 'VN', 21.2212, 105.807, 'tropical-north', ['culture', 'food', 'city']],
        ['DAD', 'Da Nang', 'Da Nang', 'Vietnam', 'VN', 16.0439, 108.199, 'tropical-seasonal', ['beach', 'food', 'culture']],
        ['SAI', 'Siem Reap–Angkor', 'Siem Reap', 'Cambodia', 'KH', 13.3697, 104.224, 'tropical-seasonal', ['culture', 'nature']],
        ['LPQ', 'Luang Prabang', 'Luang Prabang', 'Laos', 'LA', 19.9043, 102.167, 'tropical-north', ['culture', 'nature']],
        ['MNL', 'Ninoy Aquino', 'Manila', 'Philippines', 'PH', 14.5086, 121.02, 'tropical-seasonal', ['city', 'culture']],
        ['CEB', 'Mactan–Cebu', 'Cebu', 'Philippines', 'PH', 10.3093, 123.98, 'equatorial', ['beach', 'islands', 'sunny']],
        // East Asia
        ['HND', 'Haneda', 'Tokyo', 'Japan', 'JP', 35.5497, 139.787, 'east-asia', ['city', 'food', 'culture']],
        ['KIX', 'Kansai', 'Osaka', 'Japan', 'JP', 34.4273, 135.244, 'east-asia', ['food', 'city', 'culture']],
        ['CTS', 'New Chitose', 'Sapporo', 'Japan', 'JP', 42.7748, 141.69, 'nordic', ['ski', 'nature', 'food']],
        ['OKA', 'Naha', 'Okinawa', 'Japan', 'JP', 26.1924, 127.64, 'subtropical', ['beach', 'islands', 'sunny']],
        ['ICN', 'Incheon', 'Seoul', 'South Korea', 'KR', 37.4691, 126.451, 'continental', ['city', 'food', 'culture']],
        ['PVG', 'Pudong', 'Shanghai', 'China', 'CN', 31.1434, 121.805, 'east-asia', ['city', 'food']],
        ['PEK', 'Beijing Capital', 'Beijing', 'China', 'CN', 40.0773, 116.597, 'continental', ['culture', 'city']],
        ['HKG', 'Hong Kong', 'Hong Kong', 'Hong Kong', 'HK', 22.3118, 113.915, 'subtropical', ['city', 'food']],
        ['TPE', 'Taoyuan', 'Taipei', 'Taiwan', 'TW', 25.0777, 121.233, 'subtropical', ['city', 'food', 'nature']],
        // South Asia
        ['DEL', 'Indira Gandhi', 'Delhi', 'India', 'IN', 28.5556, 77.0952, 'desert', ['culture', 'food', 'city']],
        ['BOM', 'Chhatrapati Shivaji Maharaj', 'Mumbai', 'India', 'IN', 19.0887, 72.8679, 'tropical-seasonal', ['city', 'food', 'beach']],
        ['GOI', 'Dabolim', 'Goa', 'India', 'IN', 15.3801, 73.8333, 'tropical-seasonal', ['beach', 'party', 'sunny']],
        ['COK', 'Cochin', 'Kochi', 'India', 'IN', 10.151, 76.4008, 'tropical-seasonal', ['nature', 'food', 'beach']],
        ['CMB', 'Bandaranaike', 'Colombo', 'Sri Lanka', 'LK', 7.18076, 79.8841, 'tropical-seasonal', ['beach', 'culture', 'nature']],
        ['MLE', 'Velana', 'Malé', 'Maldives', 'MV', 4.19183, 73.5291, 'equatorial', ['beach', 'islands', 'sunny']],
        // The Middle East
        ['DXB', 'Dubai', 'Dubai', 'United Arab Emirates', 'AE', 25.2498, 55.371, 'gulf', ['city', 'beach', 'sunny', 'party']],
        ['AUH', 'Zayed', 'Abu Dhabi', 'United Arab Emirates', 'AE', 24.441, 54.6492, 'gulf', ['city', 'beach', 'sunny']],
        ['DOH', 'Hamad', 'Doha', 'Qatar', 'QA', 25.2731, 51.6081, 'gulf', ['city', 'culture']],
        ['MCT', 'Muscat', 'Muscat', 'Oman', 'OM', 23.6002, 58.2853, 'gulf', ['nature', 'beach', 'culture']],
        ['JED', 'King Abdulaziz', 'Jeddah', 'Saudi Arabia', 'SA', 21.6802, 39.1574, 'gulf', ['culture', 'beach']],
        ['AMM', 'Queen Alia', 'Amman', 'Jordan', 'JO', 31.7226, 35.9932, 'levant', ['culture', 'nature']],
        ['TLV', 'Ben Gurion', 'Tel Aviv', 'Israel', 'IL', 32.0114, 34.8867, 'levant', ['beach', 'city', 'party', 'food']],
        // Africa and the Indian Ocean
        ['CAI', 'Cairo', 'Cairo', 'Egypt', 'EG', 30.1115, 31.3967, 'north-africa', ['culture', 'city']],
        ['CPT', 'Cape Town', 'Cape Town', 'South Africa', 'ZA', -33.974, 18.6043, 'southern-med', ['city', 'nature', 'beach', 'food']],
        ['JNB', 'O. R. Tambo', 'Johannesburg', 'South Africa', 'ZA', -26.1401, 28.2468, 'highland', ['city', 'culture']],
        ['NBO', 'Jomo Kenyatta', 'Nairobi', 'Kenya', 'KE', -1.31889, 36.9282, 'highland', ['nature', 'culture']],
        ['ADD', 'Bole', 'Addis Ababa', 'Ethiopia', 'ET', 8.97789, 38.7993, 'highland', ['culture', 'nature']],
        ['TNR', 'Ivato', 'Antananarivo', 'Madagascar', 'MG', -18.7969, 47.4788, 'highland', ['nature', 'culture']],
        ['ZNZ', 'Abeid Amani Karume', 'Zanzibar', 'Tanzania', 'TZ', -6.22202, 39.2249, 'equatorial', ['beach', 'islands', 'sunny']],
        ['MRU', 'Sir Seewoosagur Ramgoolam', 'Mauritius', 'Mauritius', 'MU', -20.4302, 57.6836, 'tropical-seasonal', ['beach', 'islands', 'sunny']],
        ['SEZ', 'Seychelles', 'Mahé', 'Seychelles', 'SC', -4.67434, 55.5218, 'equatorial', ['beach', 'islands', 'sunny', 'nature']],
        ['RUN', 'Roland Garros', 'Réunion', 'Réunion', 'RE', -20.8901, 55.5189, 'tropical-seasonal', ['nature', 'beach', 'islands']],
        ['DSS', 'Blaise Diagne', 'Dakar', 'Senegal', 'SN', 14.6709, -17.0728, 'tropical-seasonal', ['beach', 'culture']],
        ['ACC', 'Kotoka', 'Accra', 'Ghana', 'GH', 5.60519, -0.166786, 'equatorial', ['culture', 'beach']],
        ['WDH', 'Hosea Kutako', 'Windhoek', 'Namibia', 'NA', -22.4799, 17.4709, 'southern-savanna', ['nature']],
        ['VFA', 'Victoria Falls', 'Victoria Falls', 'Zimbabwe', 'ZW', -18.0974, 25.8369, 'southern-savanna', ['nature']],
        // Oceania
        ['SYD', 'Kingsford Smith', 'Sydney', 'Australia', 'AU', -33.9461, 151.177, 'southern-med', ['city', 'beach', 'nature', 'sunny']],
        ['MEL', 'Melbourne', 'Melbourne', 'Australia', 'AU', -37.6707, 144.838, 'southern-oceanic', ['city', 'food', 'culture']],
        ['BNE', 'Brisbane', 'Brisbane', 'Australia', 'AU', -27.3842, 153.117, 'southern-subtropical', ['beach', 'sunny', 'city']],
        ['PER', 'Perth', 'Perth', 'Australia', 'AU', -31.9403, 115.967, 'southern-med', ['beach', 'sunny', 'city']],
        ['CNS', 'Cairns', 'Cairns', 'Australia', 'AU', -16.8789, 145.749, 'tropical-seasonal', ['nature', 'beach', 'islands']],
        ['AKL', 'Auckland', 'Auckland', 'New Zealand', 'NZ', -37.012, 174.786, 'southern-oceanic', ['city', 'nature']],
        ['ZQN', 'Queenstown', 'Queenstown', 'New Zealand', 'NZ', -45.0192, 168.746, 'southern-alpine', ['ski', 'nature']],
        ['NAN', 'Nadi', 'Nadi', 'Fiji', 'FJ', -17.7618, 177.438, 'tropical-seasonal', ['beach', 'islands', 'sunny']],
        ['PPT', 'Fa\'a\'ā', 'Papeete', 'French Polynesia', 'PF', -17.5535, -149.607, 'tropical-seasonal', ['beach', 'islands', 'sunny', 'nature']],
    ],
];
