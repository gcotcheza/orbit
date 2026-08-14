<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Where you can go from the Netherlands
|--------------------------------------------------------------------------
|
| Seventy-seven European (and just-past-Europe) airports with a scheduled
| short-haul service from Amsterdam, Eindhoven or Düsseldorf, plus the two
| editorial judgements the app makes about each of them: what it is FOR
| (`vibes`) and how warm it is MONTH BY MONTH (`climate`).
|
| WHY THIS IS DATA AND NOT A MIGRATION OR AN API. Nobody sells "is Faro sunny
| in March" in a form this app could use, and the answer does not change. It is
| a list, so it lives in a file that can be read and argued with, and
| Database\Seeders\DestinationSeeder puts it in the database idempotently.
|
| CLIMATE IS A PROFILE, NOT TWELVE NUMBERS PER ROW. Naples and Rome have the
| same answer to "how warm in April", and writing it twice is how they end up
| disagreeing after somebody edits one. Nine profiles cover the whole list; a
| destination names one and the seeder expands it into the twelve monthly
| ratings that land in `destinations.warmth`.
|
| THE RATING IS 1-5, NOT DEGREES: 1 "pack a coat", 2 "spring jacket",
| 3 "pleasant", 4 "shorts", 5 "beach". PR10's rule parser answers "somewhere
| sunny in spring" by asking for >= 4 in March-May, and a rating is the honest
| shape for a question that was never about temperature.
|
| VIBES come from a closed vocabulary of nine words — beach, city, culture,
| food, islands, nature, party, ski, sunny — because the rule parser has to
| match against them and an open set is one the parser can never be complete
| against.
|
| Coordinates are the AIRPORT's, to about 100 m. They are what the globe flies
| between, so a city-centre coordinate would land the plane in the wrong place.
|
| @return array{climates: array<string, list<int>>, origins: list<array{string, string, string, string, string, float, float}>, destinations: list<array{string, string, string, string, string, float, float, string, list<string>}>}
*/

return [

    /*
     * Jan .. Dec, 1-5. Read once by the seeder and expanded per destination.
     */
    'climates' => [
        'canaries' => [4, 4, 4, 4, 4, 5, 5, 5, 5, 5, 4, 4],
        'north-africa' => [3, 3, 4, 4, 5, 5, 5, 5, 5, 4, 3, 3],
        'med-south' => [2, 2, 3, 3, 4, 5, 5, 5, 5, 4, 3, 2],
        'med-north' => [2, 2, 2, 3, 4, 5, 5, 5, 4, 3, 2, 2],
        'atlantic' => [2, 2, 2, 3, 3, 4, 5, 5, 4, 3, 2, 2],
        'oceanic' => [1, 1, 2, 2, 3, 4, 4, 4, 3, 2, 1, 1],
        'continental' => [1, 1, 2, 3, 4, 4, 5, 5, 4, 3, 2, 1],
        'alpine' => [1, 1, 2, 3, 3, 4, 4, 4, 3, 2, 1, 1],
        'nordic' => [1, 1, 1, 2, 3, 3, 4, 4, 3, 2, 1, 1],
    ],

    /*
     * The three airports the owner leaves from — design/README.md §5's add-route
     * form offers exactly these. Düsseldorf is over the border and is on the
     * list anyway because it is ninety minutes from Eindhoven and routinely
     * cheaper.
     *
     * [iata, airport name, city, country, country code, lat, lng]
     */
    'origins' => [
        ['AMS', 'Amsterdam Schiphol', 'Amsterdam', 'Netherlands', 'NL', 52.3105, 4.7683],
        ['EIN', 'Eindhoven', 'Eindhoven', 'Netherlands', 'NL', 51.4500, 5.3745],
        ['DUS', 'Düsseldorf', 'Düsseldorf', 'Germany', 'DE', 51.2895, 6.7668],
    ],

    /*
     * [iata, airport name, city, country, country code, lat, lng, climate, vibes]
     */
    'destinations' => [
        // --- Iberia ---------------------------------------------------------
        ['LIS', 'Humberto Delgado', 'Lisbon', 'Portugal', 'PT', 38.7742, -9.1342, 'med-north', ['city', 'food', 'culture', 'sunny']],
        ['OPO', 'Francisco Sá Carneiro', 'Porto', 'Portugal', 'PT', 41.2481, -8.6814, 'atlantic', ['city', 'food', 'culture']],
        ['FAO', 'Faro', 'Faro', 'Portugal', 'PT', 37.0144, -7.9659, 'med-south', ['beach', 'sunny']],
        ['FNC', 'Cristiano Ronaldo', 'Funchal', 'Portugal', 'PT', 32.6979, -16.7745, 'canaries', ['nature', 'sunny', 'islands']],
        ['BCN', 'Josep Tarradellas El Prat', 'Barcelona', 'Spain', 'ES', 41.2971, 2.0785, 'med-north', ['city', 'beach', 'food', 'sunny']],
        ['MAD', 'Adolfo Suárez Barajas', 'Madrid', 'Spain', 'ES', 40.4936, -3.5668, 'continental', ['city', 'culture', 'food']],
        ['AGP', 'Málaga-Costa del Sol', 'Málaga', 'Spain', 'ES', 36.6749, -4.4991, 'med-south', ['beach', 'sunny', 'party']],
        ['ALC', 'Alicante-Elche', 'Alicante', 'Spain', 'ES', 38.2822, -0.5582, 'med-south', ['beach', 'sunny']],
        ['VLC', 'Valencia', 'Valencia', 'Spain', 'ES', 39.4893, -0.4816, 'med-south', ['city', 'beach', 'food', 'sunny']],
        ['SVQ', 'Seville', 'Seville', 'Spain', 'ES', 37.4180, -5.8931, 'med-south', ['culture', 'city', 'sunny']],
        ['PMI', 'Palma de Mallorca', 'Palma', 'Spain', 'ES', 39.5517, 2.7388, 'med-south', ['beach', 'islands', 'party', 'sunny']],
        ['IBZ', 'Ibiza', 'Ibiza', 'Spain', 'ES', 38.8729, 1.3731, 'med-south', ['beach', 'party', 'islands', 'sunny']],
        ['TFS', 'Tenerife South', 'Tenerife', 'Spain', 'ES', 28.0445, -16.5725, 'canaries', ['beach', 'nature', 'islands', 'sunny']],
        ['LPA', 'Gran Canaria', 'Las Palmas', 'Spain', 'ES', 27.9319, -15.3866, 'canaries', ['beach', 'islands', 'sunny']],
        ['ACE', 'César Manrique-Lanzarote', 'Lanzarote', 'Spain', 'ES', 28.9455, -13.6052, 'canaries', ['beach', 'nature', 'islands', 'sunny']],
        ['FUE', 'Fuerteventura', 'Fuerteventura', 'Spain', 'ES', 28.4527, -13.8638, 'canaries', ['beach', 'islands', 'sunny']],
        ['BIO', 'Bilbao', 'Bilbao', 'Spain', 'ES', 43.3011, -2.9106, 'atlantic', ['city', 'food', 'culture']],

        // --- Italy ----------------------------------------------------------
        ['FCO', 'Leonardo da Vinci Fiumicino', 'Rome', 'Italy', 'IT', 41.8003, 12.2389, 'med-north', ['city', 'culture', 'food']],
        ['MXP', 'Milan Malpensa', 'Milan', 'Italy', 'IT', 45.6306, 8.7281, 'alpine', ['city', 'food', 'ski']],
        ['VCE', 'Venice Marco Polo', 'Venice', 'Italy', 'IT', 45.5053, 12.3519, 'med-north', ['city', 'culture']],
        ['NAP', 'Naples International', 'Naples', 'Italy', 'IT', 40.8843, 14.2908, 'med-north', ['city', 'food', 'culture', 'beach']],
        ['CTA', 'Catania-Fontanarossa', 'Catania', 'Italy', 'IT', 37.4668, 15.0664, 'med-south', ['beach', 'nature', 'food', 'sunny']],
        ['PMO', 'Palermo Falcone Borsellino', 'Palermo', 'Italy', 'IT', 38.1759, 13.0910, 'med-south', ['beach', 'food', 'culture', 'sunny']],
        ['BRI', 'Bari Karol Wojtyła', 'Bari', 'Italy', 'IT', 41.1389, 16.7606, 'med-south', ['beach', 'food', 'sunny']],
        ['CAG', 'Cagliari Elmas', 'Cagliari', 'Italy', 'IT', 39.2515, 9.0543, 'med-south', ['beach', 'islands', 'sunny']],
        ['OLB', 'Olbia Costa Smeralda', 'Olbia', 'Italy', 'IT', 40.8987, 9.5176, 'med-south', ['beach', 'islands', 'sunny']],

        // --- France ---------------------------------------------------------
        ['CDG', 'Paris Charles de Gaulle', 'Paris', 'France', 'FR', 49.0097, 2.5479, 'oceanic', ['city', 'culture', 'food']],
        ['NCE', "Nice Côte d'Azur", 'Nice', 'France', 'FR', 43.6584, 7.2159, 'med-north', ['beach', 'city', 'sunny']],
        ['LYS', 'Lyon-Saint Exupéry', 'Lyon', 'France', 'FR', 45.7256, 5.0811, 'continental', ['city', 'food', 'ski']],
        ['MRS', 'Marseille Provence', 'Marseille', 'France', 'FR', 43.4393, 5.2214, 'med-north', ['beach', 'city', 'sunny']],
        ['BOD', 'Bordeaux-Mérignac', 'Bordeaux', 'France', 'FR', 44.8283, -0.7156, 'atlantic', ['food', 'city', 'nature']],
        ['AJA', 'Ajaccio Napoléon Bonaparte', 'Ajaccio', 'France', 'FR', 41.9236, 8.8029, 'med-south', ['beach', 'nature', 'islands', 'sunny']],

        // --- Greece ---------------------------------------------------------
        ['ATH', 'Athens Eleftherios Venizelos', 'Athens', 'Greece', 'GR', 37.9364, 23.9445, 'med-south', ['culture', 'city', 'sunny']],
        ['SKG', 'Thessaloniki Macedonia', 'Thessaloniki', 'Greece', 'GR', 40.5197, 22.9709, 'med-south', ['city', 'food', 'sunny']],
        ['HER', 'Heraklion Nikos Kazantzakis', 'Heraklion', 'Greece', 'GR', 35.3397, 25.1803, 'med-south', ['beach', 'islands', 'sunny']],
        ['RHO', 'Rhodes Diagoras', 'Rhodes', 'Greece', 'GR', 36.4054, 28.0862, 'med-south', ['beach', 'islands', 'sunny']],
        ['CFU', 'Corfu Ioannis Kapodistrias', 'Corfu', 'Greece', 'GR', 39.6019, 19.9117, 'med-south', ['beach', 'nature', 'islands', 'sunny']],
        ['JMK', 'Mykonos', 'Mykonos', 'Greece', 'GR', 37.4351, 25.3481, 'med-south', ['beach', 'party', 'islands', 'sunny']],
        ['JTR', 'Santorini Thira', 'Santorini', 'Greece', 'GR', 36.3992, 25.4793, 'med-south', ['beach', 'islands', 'sunny']],
        ['CHQ', 'Chania Ioannis Daskalogiannis', 'Chania', 'Greece', 'GR', 35.5317, 24.1497, 'med-south', ['beach', 'nature', 'islands', 'sunny']],

        // --- Adriatic & the Balkans ----------------------------------------
        ['SPU', 'Split', 'Split', 'Croatia', 'HR', 43.5389, 16.2981, 'med-south', ['beach', 'city', 'sunny']],
        ['DBV', 'Dubrovnik', 'Dubrovnik', 'Croatia', 'HR', 42.5614, 18.2682, 'med-south', ['beach', 'culture', 'sunny']],
        ['PUY', 'Pula', 'Pula', 'Croatia', 'HR', 44.8935, 13.9222, 'med-north', ['beach', 'nature', 'sunny']],
        ['LJU', 'Ljubljana Jože Pučnik', 'Ljubljana', 'Slovenia', 'SI', 46.2237, 14.4576, 'alpine', ['nature', 'city', 'ski']],
        ['TIA', 'Tirana Nënë Tereza', 'Tirana', 'Albania', 'AL', 41.4147, 19.7206, 'med-south', ['nature', 'city', 'sunny']],

        // --- Central Europe & the Alps --------------------------------------
        ['VIE', 'Vienna International', 'Vienna', 'Austria', 'AT', 48.1103, 16.5697, 'continental', ['city', 'culture', 'food']],
        ['SZG', 'Salzburg W. A. Mozart', 'Salzburg', 'Austria', 'AT', 47.7933, 13.0043, 'alpine', ['ski', 'nature', 'culture']],
        ['INN', 'Innsbruck', 'Innsbruck', 'Austria', 'AT', 47.2602, 11.3440, 'alpine', ['ski', 'nature']],
        ['GVA', 'Geneva', 'Geneva', 'Switzerland', 'CH', 46.2381, 6.1090, 'alpine', ['ski', 'nature', 'city']],
        ['ZRH', 'Zurich', 'Zurich', 'Switzerland', 'CH', 47.4647, 8.5492, 'alpine', ['city', 'ski', 'nature']],
        ['PRG', 'Václav Havel Prague', 'Prague', 'Czechia', 'CZ', 50.1008, 14.2600, 'continental', ['city', 'culture', 'party']],
        ['BUD', 'Budapest Ferenc Liszt', 'Budapest', 'Hungary', 'HU', 47.4369, 19.2556, 'continental', ['city', 'culture', 'party', 'food']],
        ['WAW', 'Warsaw Chopin', 'Warsaw', 'Poland', 'PL', 52.1657, 20.9671, 'continental', ['city', 'culture']],
        ['KRK', 'Kraków John Paul II', 'Kraków', 'Poland', 'PL', 50.0777, 19.7848, 'continental', ['city', 'culture', 'party']],
        ['BER', 'Berlin Brandenburg', 'Berlin', 'Germany', 'DE', 52.3667, 13.5033, 'continental', ['city', 'culture', 'party']],
        ['MUC', 'Munich Franz Josef Strauss', 'Munich', 'Germany', 'DE', 48.3538, 11.7861, 'continental', ['city', 'food', 'ski']],
        ['HAM', 'Hamburg', 'Hamburg', 'Germany', 'DE', 53.6304, 9.9882, 'oceanic', ['city', 'food']],

        // --- Britain & Ireland ----------------------------------------------
        ['LHR', 'London Heathrow', 'London', 'United Kingdom', 'GB', 51.4700, -0.4543, 'oceanic', ['city', 'culture', 'food']],
        ['EDI', 'Edinburgh', 'Edinburgh', 'United Kingdom', 'GB', 55.9500, -3.3725, 'oceanic', ['city', 'culture', 'nature']],
        ['DUB', 'Dublin', 'Dublin', 'Ireland', 'IE', 53.4213, -6.2701, 'oceanic', ['city', 'culture', 'party']],

        // --- The north ------------------------------------------------------
        ['CPH', 'Copenhagen Kastrup', 'Copenhagen', 'Denmark', 'DK', 55.6180, 12.6560, 'oceanic', ['city', 'food', 'culture']],
        ['OSL', 'Oslo Gardermoen', 'Oslo', 'Norway', 'NO', 60.1976, 11.1004, 'nordic', ['city', 'nature', 'ski']],
        ['ARN', 'Stockholm Arlanda', 'Stockholm', 'Sweden', 'SE', 59.6519, 17.9186, 'nordic', ['city', 'culture', 'nature']],
        ['HEL', 'Helsinki-Vantaa', 'Helsinki', 'Finland', 'FI', 60.3172, 24.9633, 'nordic', ['city', 'nature']],
        ['KEF', 'Keflavík', 'Reykjavík', 'Iceland', 'IS', 63.9850, -22.6056, 'nordic', ['nature']],
        ['TOS', 'Tromsø', 'Tromsø', 'Norway', 'NO', 69.6833, 18.9189, 'nordic', ['nature', 'ski']],
        ['RIX', 'Riga', 'Riga', 'Latvia', 'LV', 56.9236, 23.9711, 'nordic', ['city', 'culture']],
        ['TLL', 'Tallinn Lennart Meri', 'Tallinn', 'Estonia', 'EE', 59.4133, 24.8328, 'nordic', ['city', 'culture']],

        // --- The warm edge --------------------------------------------------
        ['IST', 'Istanbul', 'Istanbul', 'Türkiye', 'TR', 41.2753, 28.7519, 'med-north', ['city', 'culture', 'food']],
        ['AYT', 'Antalya', 'Antalya', 'Türkiye', 'TR', 36.8987, 30.8005, 'med-south', ['beach', 'sunny']],
        ['DLM', 'Dalaman', 'Dalaman', 'Türkiye', 'TR', 36.7131, 28.7925, 'med-south', ['beach', 'nature', 'sunny']],
        ['MLA', 'Malta', 'Valletta', 'Malta', 'MT', 35.8575, 14.4775, 'med-south', ['beach', 'culture', 'islands', 'sunny']],
        ['LCA', 'Larnaca', 'Larnaca', 'Cyprus', 'CY', 34.8751, 33.6249, 'med-south', ['beach', 'islands', 'sunny']],
        ['PFO', 'Paphos', 'Paphos', 'Cyprus', 'CY', 34.7180, 32.4857, 'med-south', ['beach', 'islands', 'sunny']],
        ['RAK', 'Marrakesh Menara', 'Marrakesh', 'Morocco', 'MA', 31.6069, -8.0363, 'north-africa', ['culture', 'city', 'sunny']],
        ['AGA', 'Agadir Al Massira', 'Agadir', 'Morocco', 'MA', 30.3250, -9.4131, 'north-africa', ['beach', 'sunny']],
        ['SSH', 'Sharm el-Sheikh', 'Sharm el-Sheikh', 'Egypt', 'EG', 27.9773, 34.3950, 'north-africa', ['beach', 'sunny']],
    ],

];
