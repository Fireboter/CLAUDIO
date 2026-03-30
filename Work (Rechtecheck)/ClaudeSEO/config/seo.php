<?php
return [
    'site_name'    => 'Rechtecheck',
    'site_url'     => 'https://rechtecheck.de',
    'cta_base_url' => 'https://rechtecheck.de/experten-service/',

    'cities_tier1' => [
        'Berlin','München','Hamburg','Köln','Frankfurt',
        'Stuttgart','Düsseldorf','Leipzig','Dortmund','Essen',
        'Bremen','Dresden','Hannover','Nürnberg','Duisburg',
    ],
    'cities_tier2' => [
        'Bochum','Wuppertal','Bielefeld','Bonn','Münster',
        'Mannheim','Karlsruhe','Augsburg','Wiesbaden',
        'Mönchengladbach','Gelsenkirchen','Aachen','Braunschweig',
        'Kiel','Chemnitz','Freiburg','Mainz','Rostock',
    ],
    'cities_tier3' => [
        'Lübeck','Erfurt','Hagen','Kassel','Oberhausen',
        'Hamm','Saarbrücken','Herne','Solingen','Leverkusen',
        'Neuss','Heidelberg','Paderborn','Darmstadt','Regensburg',
        'Würzburg','Ingolstadt','Wolfsburg','Ulm','Heilbronn',
        'Göttingen','Reutlingen','Koblenz','Bremerhaven','Trier',
        'Jena','Erlangen','Moers','Cottbus','Siegen',
    ],

    'safeguards' => [
        'max_api_calls_per_day'   => 500,
        'max_cost_per_day_cents'  => 500,
        'cooldown_after_generate' => 7,
        'cooldown_after_optimize' => 14,
        'min_days_before_judging' => 30,
        'min_days_before_delete'  => 60,
    ],
];
