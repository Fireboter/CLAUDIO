<?php
/**
 * Variation Types & Values Seeder
 * Clears and re-seeds variation_types + variation_values for all 46 Rechtsgebiete
 * 5 types per Rechtsgebiet: Personengruppen, Context-specific, Besondere Umstände, Städte, Generelle Informationen
 */

$cfg = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4",
    $cfg['user'],
    $cfg['pass']
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ─── Helper ──────────────────────────────────────────────────────────────────

function slug(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $map = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        ' ' => '-', '/' => '-', '&' => 'und',
        '(' => '', ')' => '', '.' => '', ',' => '',
        '+' => '', '\'' => '', '"' => '',
    ];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9\-]/', '', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

// ─── Shared: Städte (97 German cities) ───────────────────────────────────────

$CITIES = [
    'Berlin', 'Hamburg', 'München', 'Köln', 'Frankfurt am Main', 'Stuttgart',
    'Düsseldorf', 'Leipzig', 'Dortmund', 'Essen', 'Bremen', 'Dresden',
    'Hannover', 'Nürnberg', 'Duisburg', 'Bochum', 'Wuppertal', 'Bielefeld',
    'Bonn', 'Münster', 'Karlsruhe', 'Mannheim', 'Augsburg', 'Wiesbaden',
    'Gelsenkirchen', 'Mönchengladbach', 'Braunschweig', 'Kiel', 'Chemnitz',
    'Aachen', 'Halle', 'Magdeburg', 'Freiburg im Breisgau', 'Krefeld',
    'Lübeck', 'Oberhausen', 'Erfurt', 'Mainz', 'Rostock', 'Kassel',
    'Hagen', 'Hamm', 'Saarbrücken', 'Mülheim an der Ruhr', 'Potsdam',
    'Ludwigshafen', 'Oldenburg', 'Leverkusen', 'Osnabrück', 'Solingen',
    'Heidelberg', 'Darmstadt', 'Regensburg', 'Herne', 'Paderborn', 'Neuss',
    'Ingolstadt', 'Offenbach am Main', 'Fürth', 'Würzburg', 'Ulm',
    'Heilbronn', 'Pforzheim', 'Wolfsburg', 'Göttingen', 'Bottrop',
    'Reutlingen', 'Erlangen', 'Koblenz', 'Bremerhaven', 'Bergisch Gladbach',
    'Remscheid', 'Jena', 'Trier', 'Recklinghausen', 'Siegen', 'Salzgitter',
    'Gütersloh', 'Hildesheim', 'Kaiserslautern', 'Cottbus', 'Gera', 'Moers',
    'Zwickau', 'Iserlohn', 'Schwerin', 'Witten', 'Düren', 'Ratingen',
    'Esslingen am Neckar', 'Marl', 'Ludwigsburg', 'Velbert', 'Norderstedt',
    'Hanau', 'Dessau-Roßlau', 'Flensburg',
];

// ─── Shared: Generelle Informationen (20 values) ─────────────────────────────

$GENERELLE = [
    'Kosten und Anwaltsgebühren',
    'Ablauf und Verfahren Schritt für Schritt',
    'Fristen und Verjährung',
    'Rechte und Pflichten',
    'Was tun – erste Schritte',
    'Tipps vom Fachanwalt',
    'Auch ohne Anwalt möglich',
    'Mit Rechtsschutzversicherung',
    'Online Rechtsberatung',
    'Wie lange dauert es',
    'Erfolgschancen einschätzen',
    'Außergerichtliche Einigung',
    'Den richtigen Anwalt finden',
    'Häufige Fehler vermeiden',
    'Wichtige Beweise sichern',
    'Kostenlose Erstberatung nutzen',
    'Prozesskostenhilfe beantragen',
    'Einstweiligen Rechtsschutz beantragen',
    'Für Unternehmen und Firmen',
    'Mit besonderer persönlicher Situation',
];

// ─── Data Definition ─────────────────────────────────────────────────────────
// Format: rechtsgebiet_id => [ [typeName, [values...]], ... ]
// Types 1-3 are custom per RG; type 4 = Städte; type 5 = Generelle Informationen

$data = [];

// ── 1: Anwaltshaftung ────────────────────────────────────────────────────────
$data[1] = [
    ['Mandantengruppen', [
        'Privatperson', 'Unternehmen', 'GmbH', 'AG', 'Selbstständiger',
        'Freiberufler', 'Verein', 'Erbengemeinschaft', 'WEG', 'Arztpraxis',
        'Handwerksbetrieb', 'Immobilienbesitzer', 'Landwirt', 'Existenzgründer',
        'Stiftung', 'Ehepaar', 'Minderjähriger', 'Rentnerpaar',
        'Kleingewerbetreibender', 'Gemeinnützige Organisation',
    ]],
    ['Rechtsgebiete des Anwalts', [
        'Arbeitsrecht', 'Mietrecht', 'Familienrecht', 'Erbrecht', 'Strafrecht',
        'Vertragsrecht', 'Steuerrecht', 'Gesellschaftsrecht', 'Verkehrsrecht',
        'Baurecht', 'Medizinrecht', 'Sozialrecht', 'Verwaltungsrecht',
        'Versicherungsrecht', 'IT-Recht', 'Markenrecht', 'Patentrecht',
        'Insolvenzrecht', 'Immobilienrecht', 'Scheidungsrecht',
    ]],
    ['Schadenssituationen', [
        'Frist verpasst', 'Falsch beraten', 'Verjährung eingetreten',
        'Klage falsch erhoben', 'Beweismittel nicht gesichert',
        'Falscher Rechtsmittelweg', 'Unterlassene Berufung', 'Falscher Vergleich',
        'Schlechte Verhandlungsführung', 'Mandate nicht erkannt',
        'Falscher Anwalt beauftragt', 'Doppelte Kosten entstanden',
        'Prozess verloren', 'Schadensersatz nicht eingefordert',
        'Unzureichende Aufklärung', 'Widerspruch vergessen', 'Falsche Vollmacht',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 2: Architektenrecht ──────────────────────────────────────────────────────
$data[2] = [
    ['Auftraggeber', [
        'Privatbauherr', 'Gewerblicher Bauherr', 'Bauträger',
        'Immobilieninvestor', 'Gemeinde', 'Wohnbaugesellschaft',
        'Kirchliche Träger', 'Krankenhausträger', 'Schulbehörde',
        'Industriebetrieb', 'Projektentwickler', 'Erbengemeinschaft',
        'GmbH als Bauherr', 'Genossenschaft', 'Private Equity Fonds',
    ]],
    ['Bauvorhaben-Typen', [
        'Neubau Einfamilienhaus', 'Sanierung Einfamilienhaus',
        'Mehrfamilienhaus Neubau', 'Wohnanlage', 'Bürogebäude',
        'Gewerbegebäude', 'Dachgeschossausbau', 'Anbau und Aufstockung',
        'Denkmalschutz-Sanierung', 'Industriehalle', 'Krankenhaus Neubau',
        'Schulgebäude', 'Hotel', 'Tiefgarage', 'Ferienhaus', 'Ladenbau',
        'Gewerbliche Sanierung',
    ]],
    ['Planungs- und Ausführungsprobleme', [
        'Kostenüberschreitung', 'Bauverzögerung', 'Fehlerhafte Statik',
        'Ungültige Baugenehmigung', 'Fehlende Koordination',
        'Mangelhafte Bauleitung', 'Wärmedämmungsmängel', 'Schallschutzmängel',
        'Feuchtigkeitsschäden durch Planung', 'Energetische Mängel',
        'Falsche Maßangaben', 'Fehlende Sicherheitsaspekte',
        'Nicht genehmigungsfähige Planung', 'Schlechte Ausschreibung',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 3: Asylrecht ─────────────────────────────────────────────────────────────
$data[3] = [
    ['Herkunftsländer', [
        'Syrien', 'Afghanistan', 'Irak', 'Iran', 'Somalia', 'Eritrea',
        'Nigeria', 'Türkei', 'Russland', 'Georgien', 'Albanien', 'Pakistan',
        'Äthiopien', 'Sudan', 'Vietnam', 'China', 'Indien', 'Serbien',
        'Kosovo', 'Nordmazedonien', 'Guinea', 'Kamerun', 'Ghana',
        'Bangladesh', 'Sri Lanka', 'Ukraine', 'Belarus', 'Tunesien',
        'Marokko', 'Algerien', 'Libyen', 'Gambia',
    ]],
    ['Familien- und Lebenssituationen', [
        'Mit Ehepartner', 'Mit minderjährigen Kindern',
        'Als unbegleiteter Minderjähriger', 'Als Alleinerziehende Mutter',
        'Mit Behinderung', 'Mit schwerer Erkrankung', 'Schwanger',
        'Als gut ausgebildete Fachkraft', 'Als Student', 'Ohne Dokumente',
        'Als LGBTQ+ Person', 'Als politisch Verfolgter', 'Als Kriegsflüchtling',
        'Als Opfer von Menschenhandel', 'Als Opfer häuslicher Gewalt',
        'Mit guten Deutschkenntnissen',
    ]],
    ['Aufenthaltssituationen', [
        'Im laufenden Asylverfahren', 'Abgelehnt im Klageverfahren',
        'Im Dublin-Verfahren', 'Mit Duldung lebend', 'Mit subsidiärem Schutz',
        'Mit anerkanntem Flüchtlingsstatus', 'Ausreisepflichtig',
        'Vor drohendem Abschiebetermin', 'Im Ankerzentrum',
        'In dezentraler Unterkunft', 'Seit weniger als einem Jahr',
        'Seit mehr als 3 Jahren', 'Seit über 5 Jahren',
        'Im Widerspruchsverfahren',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 4: Baurecht ──────────────────────────────────────────────────────────────
$data[4] = [
    ['Bauherren und Beteiligte', [
        'Privatperson als Bauherr', 'Gewerblicher Bauherr', 'Bauträger',
        'Grundstückseigentümer', 'Wohnbaugesellschaft', 'Gemeinde',
        'Investor', 'Erbengemeinschaft', 'WEG', 'GmbH als Bauherr',
        'Pächter', 'Mieter mit Umbauplänen',
    ]],
    ['Bauvorhaben-Arten', [
        'Neubau Einfamilienhaus', 'Mehrfamilienhaus', 'Wohnanlage',
        'Bürogebäude', 'Lager und Halle', 'Carport und Garage',
        'Gartenhaus über 30m²', 'Swimmingpool', 'Wintergarten',
        'Dachausbau', 'Aufstockung', 'Anbau', 'Fertighaus',
        'Sanierung Altbau', 'Abriss und Neubau', 'Solaranlage aufs Dach',
        'Balkon anbringen', 'Zaunbau', 'Abrissgenehmigung',
    ]],
    ['Rechtliche Bausituationen', [
        'Baugenehmigung beantragen', 'Bebauungsplan Abweichung',
        'Nutzungsänderung', 'Nachbarwiderspruch', 'Schwarzbau legalisieren',
        'Abstandsflächen', 'Denkmalschutz betroffen', 'Naturschutzbereich',
        'Erschließungskosten', 'Bebauungsplan ändern', 'Flächennutzungsplan',
        'Bauordnungswidrigkeit', 'Baulastverzeichnis', 'Ausnahmegenehmigung',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 5: Bankrecht ─────────────────────────────────────────────────────────────
$data[5] = [
    ['Bankkundengruppen', [
        'Privatperson', 'Selbstständiger', 'GmbH', 'Freiberufler',
        'Verbraucher', 'Kleinunternehmer', 'Mittelständler', 'Rentner',
        'Student', 'Auszubildender', 'Existenzgründer', 'Landwirt',
        'Verein', 'Immobilieninvestor', 'Erbengemeinschaft',
        'Ausländer mit Konto',
    ]],
    ['Bankprodukte und Finanzinstrumente', [
        'Girokonto', 'Ratenkredit', 'Baufinanzierung', 'Dispositionskredit',
        'Kreditkarte', 'Tagesgeldkonto', 'Festgeldanlage', 'Wertpapierdepot',
        'Bausparvertrag', 'Lebensversicherung', 'Leasing', 'Bürgschaft',
        'Kontopfändung', 'Fondssparplan', 'Betrieblicher Kredit',
        'Unternehmensfinanzierung',
    ]],
    ['Bankrechtliche Problemsituationen', [
        'Kredit abgelehnt', 'Konto gesperrt', 'Schufa-Eintrag zu Unrecht',
        'Überschuldung droht', 'Vorfälligkeitsentschädigung gefordert',
        'Fehlerhafte Kontoabrechnung', 'Falschberatung bei Geldanlage',
        'Zu hohe Bankgebühren', 'Betrug auf dem Konto',
        'Identitätsdiebstahl', 'Widerruf eines Vertrags', 'Negativzinsen',
        'Kontoauflösung verweigert', 'Kredit widerrufen',
        'Restschuldbefreiung',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 6: Beamtenrecht ──────────────────────────────────────────────────────────
$data[6] = [
    ['Beamtengruppen', [
        'Bundesbeamter', 'Landesbeamter', 'Kommunalbeamter',
        'Polizeibeamter', 'Lehrkraft an Schule', 'Staatsanwalt', 'Richter',
        'Feuerwehrbeamter', 'Zollbeamter', 'Bundeswehrsoldat',
        'Hochschulprofessor', 'Justizvollzugsbeamter', 'Finanzbeamter',
        'Sozialarbeiter im öD', 'Verwaltungsbeamter', 'Bibliotheksbeamter',
    ]],
    ['Dienstbehörden', [
        'Schule und Schulamt', 'Polizeipräsidium', 'Finanzamt',
        'Staatsanwaltschaft', 'Amtsgericht', 'Bundeswehr',
        'Bundesministerium', 'Landesministerium', 'Stadtverwaltung',
        'Kreisverwaltung', 'Jobcenter Behörde', 'Zollbehörde', 'Gemeinde',
        'Regierungspräsidium', 'Amt für öffentliche Ordnung',
    ]],
    ['Beamtenrechtliche Situationen', [
        'In der Probezeit', 'Krankheit und Dienstunfähigkeit',
        'Vor der Pensionierung', 'Als Teilzeitbeamter',
        'Mit anerkannter Schwerbehinderung', 'Mit Nebentätigkeit',
        'Mit Betreuungspflichten', 'Im Auslandsaufenthalt',
        'Nach negativer Beurteilung', 'Vor Beförderungsentscheidung',
        'Bei Versetzungsbefehl', 'Im Disziplinarverfahren',
        'Nach langer Krankheit', 'Bei Altersteilzeit', 'Bei Elternzeit',
        'Bei Umsetzung',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 7: Behindertenrecht ──────────────────────────────────────────────────────
$data[7] = [
    ['Behinderungsgruppen', [
        'Person mit Körperbehinderung', 'Person mit Sehbehinderung',
        'Person mit Hörbehinderung', 'Person mit geistiger Behinderung',
        'Person mit psychischer Erkrankung', 'Person mit chronischer Erkrankung',
        'Person mit Mehrfachbehinderung', 'Kind mit Behinderung',
        'Jugendlicher mit Behinderung', 'Blinder', 'Rollstuhlfahrer',
        'Hörgerät-Träger', 'MS-Patient', 'Epilepsie-Patient', 'ALS-Patient',
        'Autismus-Betroffener',
    ]],
    ['Lebensbereiche', [
        'Arbeit und Beruf mit Behinderung', 'Wohnen und Wohnungsanpassung',
        'Schule und inklusive Bildung', 'Mobilität und Fahrdienste',
        'Gesundheitsversorgung', 'Freizeitgestaltung',
        'Öffentliche Einrichtungen', 'Digitale Barrierefreiheit',
        'Rehabilitation', 'Sport und Inklusion', 'Soziale Teilhabe',
        'Öffentlicher Nahverkehr',
    ]],
    ['Rechtliche Problemsituationen', [
        'GdB-Feststellung zu niedrig', 'Schwerbehindertenausweis verweigert',
        'Nachteilsausgleiche nicht gewährt', 'Werkstattplatz abgelehnt',
        'Persönliches Budget verweigert', 'Eingliederungshilfe abgelehnt',
        'Hilfsmittel von Kasse verweigert', 'Pflegegrad zu niedrig',
        'Arbeitsplatzhilfe verweigert', 'Schulbegleitung abgelehnt',
        'Barrierefreie Wohnung fehlt', 'Fahrtkosten nicht übernommen',
        'Rehamaßnahme abgelehnt',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 8: Betäubungsmittelstrafrecht ────────────────────────────────────────────
$data[8] = [
    ['Personengruppen', [
        'Ersttäter', 'Gelegentlicher Konsument', 'Abhängiger',
        'Händler und Dealer', 'Jugendlicher unter 18', 'Heranwachsender 18-21',
        'Erwachsener', 'Rückfalltäter', 'Ausländischer Beschuldigter',
        'Elternteil mit Kindern', 'Beamter', 'Lehrer', 'Arzt', 'Kraftfahrer',
        'Sportler', 'Person in Therapie',
    ]],
    ['Substanzen', [
        'Cannabis', 'Kokain', 'Heroin', 'Amphetamin Speed', 'MDMA Ecstasy',
        'Crystal Meth', 'LSD', 'Pilze Psilocybin', 'Ketamin',
        'Opioide verschreibungspflichtig', 'Codein',
        'Neue psychoaktive Stoffe', 'Medikamentenmissbrauch', 'Lachgas',
        'Khat', 'Crack', 'Fentanyl',
    ]],
    ['Delikts- und Tatumstände', [
        'Geringe Menge Eigenkonsum', 'Nicht geringe Menge',
        'Unerlaubter Handel', 'Einfuhr aus Ausland', 'Anbau Cannabis',
        'In der Öffentlichkeit erwischt', 'In der Schule', 'Am Arbeitsplatz',
        'Im Straßenverkehr', 'Bandenmäßig organisiert', 'Mit Minderjährigen',
        'Über Darknet', 'Bei Hausdurchsuchung', 'Mit gleichzeitiger Waffe',
        'Durch verdeckten Ermittler',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 9: Bußgeld ───────────────────────────────────────────────────────────────
$data[9] = [
    ['Fahrergruppen', [
        'PKW-Fahrer privat', 'Berufskraftfahrer', 'Taxifahrer',
        'Motorradfahrer', 'Mopedfahrer', 'Fahrradfahrer', 'E-Scooter-Nutzer',
        'Busfahrer', 'Lieferdienstfahrer', 'Firmenwagenfahrer',
        'Fahranfänger in Probezeit', 'Älterer Fahrer', 'Ausländischer Fahrer',
        'LKW-Fahrer', 'Transporter-Fahrer',
    ]],
    ['Verkehrssituationen', [
        'Innerorts 30er Zone', 'Innerorts 50er Zone', 'Außerorts Landstraße',
        'Autobahn', 'Baustellenbereich', 'Wohngebiet verkehrsberuhigt',
        'Schulzone und Spielstraße', 'Kreuzungsbereich', 'Kreisverkehr',
        'Parkzone Innenstadt', 'Bundesstraße', 'Tunnel', 'Bahnübergang',
        'Ampelbereich', 'Kurvenbereich', 'Überholbereich',
    ]],
    ['Verstöße und Vergehen', [
        'Zu schnell 1-10 km/h', 'Zu schnell 11-20 km/h',
        'Zu schnell 21-30 km/h', 'Zu schnell 31-40 km/h',
        'Zu schnell über 40 km/h', 'Rotlicht unter 1 Sekunde',
        'Rotlicht über 1 Sekunde', 'Handy am Steuer', 'Zu geringer Abstand',
        'Verbotenes Überholen', 'Auf Autobahn gewendet',
        'Alkohol 0,5 Promille', 'Alkohol über 1,6 Promille',
        'Drogen im Blut', 'Kind nicht gesichert', 'Gurt nicht angelegt',
        'Falsch geparkt Halteverbot', 'Abgelaufener TÜV',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 10: Compliance ───────────────────────────────────────────────────────────
$data[10] = [
    ['Unternehmenstypen', [
        'Startup', 'Kleines Unternehmen', 'Mittelständisches Unternehmen',
        'Konzern', 'Internationaler Konzern', 'Börsennotiertes Unternehmen',
        'Familienunternehmen', 'Öffentliches Unternehmen',
        'Bank und Versicherung', 'Pharmaunternehmen', 'Rüstungsunternehmen',
        'Lebensmittelunternehmen', 'Energieunternehmen', 'IT-Unternehmen',
        'Handelsunternehmen',
    ]],
    ['Branchen', [
        'Finanzwesen und Banking', 'Pharma und Medizin',
        'Rüstung und Sicherheit', 'Lebensmittel und Handel',
        'Energie und Umwelt', 'Technologie und IT', 'Automobilindustrie',
        'Logistik und Transport', 'Immobilien und Bau',
        'Medien und Telekommunikation', 'Chemie und Industrie',
        'Gesundheitswesen', 'Bildung und Forschung',
    ]],
    ['Compliance-Bereiche', [
        'Datenschutz DSGVO', 'Anti-Korruption und Bestechung',
        'Geldwäscheprävention', 'Kartellrecht Compliance',
        'Exportkontrolle und Sanktionen', 'Insiderhandel Prävention',
        'Lieferkettensorgfalt', 'Umwelt-Compliance', 'Steuer-Compliance',
        'Arbeitnehmerrechte', 'Produkthaftung Compliance', 'Datensicherheit IT',
        'Finanzmarkt-Compliance', 'Marktmissbrauch',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 11: Diesel ───────────────────────────────────────────────────────────────
$data[11] = [
    ['Betroffene Fahrzeugbesitzer', [
        'Privatperson', 'Kleingewerbetreibender', 'Unternehmen mit Fuhrpark',
        'Leasingnehmer', 'Flottenmanager', 'Taxiunternehmer', 'Handwerker',
        'Landwirt', 'Gebrauchtwagenkäufer', 'Neuwagenkäufer',
        'Fahrzeugvermieter', 'Selbstständiger Fahrer',
    ]],
    ['Fahrzeugmarken', [
        'Volkswagen VW', 'Audi', 'Porsche', 'Seat', 'Skoda',
        'Mercedes-Benz', 'BMW', 'Opel', 'Renault', 'Fiat', 'Jeep',
        'Land Rover', 'Nissan', 'Peugeot', 'Citroën', 'Alfa Romeo',
        'Volvo', 'Dodge', 'Hyundai', 'Kia', 'Mazda', 'Ford', 'Chevrolet',
    ]],
    ['Fahrzeugmodelle', [
        'VW Golf Diesel', 'VW Passat Diesel', 'VW Tiguan Diesel',
        'VW Touareg Diesel', 'Audi A4 TDI', 'Audi A6 TDI', 'Audi Q5 TDI',
        'Audi Q7 TDI', 'Mercedes C-Klasse CDI', 'Mercedes E-Klasse CDI',
        'Mercedes GLC Diesel', 'BMW 3er Diesel', 'BMW 5er Diesel',
        'BMW X3 Diesel', 'Opel Astra Diesel', 'Opel Insignia Diesel',
        'Renault Kadjar Diesel', 'Nissan Qashqai Diesel',
        'Jeep Cherokee Diesel', 'Fiat Tipo Diesel',
        'Land Rover Discovery Diesel',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 12: Erbrecht ─────────────────────────────────────────────────────────────
$data[12] = [
    ['Erbberechtigte Personengruppen', [
        'Ehepartner oder Lebenspartner', 'Eheliches Kind', 'Adoptivkind',
        'Pflegekind', 'Uneheliches Kind', 'Stiefkind', 'Enkel',
        'Geschwister', 'Eltern des Erblassers', 'Neffe oder Nichte',
        'Nicht verwandter Erbe', 'Expartner', 'Erbengemeinschaft',
        'Alleinerbe', 'Minderjähriges Kind als Erbe',
        'Behindertes Kind als Erbe',
    ]],
    ['Nachlassbestandteile', [
        'Immobilien und Grundstücke', 'Bankguthaben und Ersparnisse',
        'Wertpapierdepot', 'Unternehmensanteile GmbH', 'Fahrzeuge und PKW',
        'Schmuck und Kunstobjekte', 'Kryptowährungen', 'Lebensversicherung',
        'Rentenansprüche', 'Schulden und Verbindlichkeiten', 'Urheberrechte',
        'Patente und Lizenzen', 'Auslandsimmobilien', 'Genossenschaftsanteile',
        'Sammlungen und Antiquitäten',
    ]],
    ['Familien- und Lebenssituationen', [
        'Patchworkfamilie', 'Zweite Ehe mit Kindern aus erster Ehe',
        'Unverheiratet zusammenlebend', 'Kinderlos',
        'Mit minderjährigen Kindern', 'Behindertes Kind',
        'Sehr vermögender Erblasser', 'Überschuldeter Erblasser',
        'Im Ausland lebend', 'Ausländischer Erblasser',
        'Nachlass im Ausland', 'Mehrere Erbschaftsfälle',
        'Erblasser ohne Testament', 'Erblasser mit aktuellem Testament',
        'Erbstreit mit Geschwistern',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 13: Fluggastrecht ────────────────────────────────────────────────────────
$data[13] = [
    ['Reisende', [
        'Privatperson Urlaub', 'Geschäftsreisender', 'Familie mit Kindern',
        'Rentner', 'Student', 'Gruppenreisende', 'Kreuzfahrtpassagier',
        'Vielflieger', 'Behinderter Reisender', 'Schwangere',
        'Alleinreisender Minderjähriger', 'Tierhalter im Flieger',
        'Sportler mit Ausrüstung',
    ]],
    ['Flugrouten und Distanzen', [
        'Inlandsflug unter 500 km', 'Innereuropäisch Kurzstrecke',
        'Europa Mittelstrecke', 'Transatlantikflug', 'Asienflug Langstrecke',
        'Fernflug Australien', 'Nahostflug', 'Afrikaflug',
        'Mit Zwischenstopp', 'Mit Umstieg Kurzverbindung', 'Direktflug',
        'Charterflug Pauschalreise', 'Low-Cost-Airline',
    ]],
    ['Problemsituationen Flug', [
        'Flug annulliert kurzfristig', 'Über 3 Stunden Verspätung',
        'Überbuchung Nichtbeförderung', 'Gepäck verloren',
        'Gepäck beschädigt', 'Gepäck verspätet',
        'Verbindungsflug verpasst', 'Airline insolvent',
        'Streik des Personals', 'Technischer Defekt', 'Schlechtwetter',
        'Einreise verweigert', 'Downgrade Business zu Economy',
        'Sitzplatz getauscht',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 14: Gesellschaftsrecht ───────────────────────────────────────────────────
$data[14] = [
    ['Unternehmensrollen', [
        'Gründer und Mitgründer', 'Mehrheitsgesellschafter',
        'Minderheitsgesellschafter', 'Fremdgeschäftsführer',
        'Investor und Beteiligung', 'Erbe eines Gesellschaftsanteils',
        'Stiller Gesellschafter', 'Treuhänder', 'Kommanditist',
        'Komplementär', 'Business Angel', 'Venture-Capital-Investor',
        'Mitarbeiter mit Optionen',
    ]],
    ['Gesellschaftsformen und Unternehmenstypen', [
        'GmbH', 'UG haftungsbeschränkt', 'AG', 'GbR', 'OHG', 'KG',
        'GmbH & Co KG', 'PartG', 'eG Genossenschaft',
        'SE Europäische Gesellschaft', 'Einzelunternehmen',
        'Holding und Konzern', 'Ausländische Gesellschaft in DE',
    ]],
    ['Unternehmenslebensphasen und -situationen', [
        'Unternehmensgründung', 'Kapitalerhöhung', 'Gesellschaftereintritt',
        'Gesellschafteraustritt', 'Gesellschafterstreit',
        'Geschäftsführerwechsel', 'Unternehmensverkauf',
        'Fusion und Übernahme', 'Umwandlung und Formwechsel',
        'Unternehmensnachfolge', 'Sanierung', 'Auflösung und Liquidation',
        'Insolvenz des Unternehmens', 'Wettbewerbsverbot nach Austritt',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 15: IT-Recht ─────────────────────────────────────────────────────────────
$data[15] = [
    ['Digitale Akteure', [
        'Startup-Gründer', 'Softwareentwickler', 'App-Betreiber',
        'Webseitenbetreiber', 'Online-Shop-Inhaber', 'Content Creator',
        'Blogger und Influencer', 'YouTuber', 'Unternehmen mit Webpräsenz',
        'Datenschutzbeauftragter', 'IT-Dienstleister', 'SaaS-Anbieter',
        'Plattformbetreiber', 'Marktplatzbetreiber', 'KI-Unternehmen',
        'Freiberuflicher IT-Experte',
    ]],
    ['Digitale Bereiche', [
        'Webseite und Onlineshop', 'Mobile App iOS oder Android',
        'Social Media Plattform', 'E-Mail Marketing', 'Cloud-Dienste',
        'KI und Machine Learning', 'IoT und Smart Devices', 'Online-Gaming',
        'Streaming-Plattform', 'Fintech und Zahlungen', 'Edtech',
        'Healthtech', 'PropTech', 'Crypto und NFT',
    ]],
    ['IT-Rechtliche Probleme', [
        'DSGVO-Abmahnung erhalten', 'Datenschutzbußgeld droht',
        'Datenleck passiert', 'Impressumsfehler abgemahnt',
        'Cookie-Banner rechtswidrig', 'Newsletterversand ohne Einwilligung',
        'Softwarelizenz-Streit', 'Domainstreit',
        'Markenverletzung online', 'Urheberrechtsverletzung Bilder',
        'Wettbewerbsrecht Verstoß', 'KI-generierte Inhalte Recht',
        'Gefälschte Bewertungen', 'Haftung für Nutzerbeiträge',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 16: Inkassorecht ─────────────────────────────────────────────────────────
$data[16] = [
    ['Schuldnergruppen', [
        'Privatperson', 'Selbstständiger', 'GmbH', 'Jugendlicher', 'Rentner',
        'Geringverdiener', 'Bürgergeldempfänger', 'Überschuldeter',
        'Im Insolvenzverfahren', 'Mit Behinderung', 'Alleinerziehende',
        'Ehepaar', 'Student', 'Azubi', 'Arbeitnehmer mit Lohnpfändung',
    ]],
    ['Forderungsarten', [
        'Mietrückstand', 'Verbraucherkredit', 'Online-Shopping Schulden',
        'Mobilfunkvertrag', 'Stromrechnung', 'Gasrechnung',
        'Arzt- und Krankenhausrechnung', 'Fitnessstudio-Beitrag',
        'Abonnement-Schulden', 'Bürgschaft fällig', 'Privates Darlehen',
        'Steuerrückstand', 'Unterhaltsrückstand', 'Kfz-Kaufvertrag',
        'Werkvertragsschulden', 'Versicherungsprämie',
    ]],
    ['Inkasso-Verfahrensstufen', [
        'Erstes Inkassoschreiben', 'Zweites Mahnschreiben',
        'Mahnbescheid erhalten', 'Vollstreckungsbescheid',
        'Lohnpfändung droht', 'Kontopfändung',
        'Sachpfändung durch Gerichtsvollzieher',
        'Schufa-Eintrag durch Inkasso', 'Schulden möglicherweise verjährt',
        'Forderung möglicherweise falsch', 'Doppelte Forderung',
        'Insolvenzeröffnung',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 17: Insolvenzrecht ───────────────────────────────────────────────────────
$data[17] = [
    ['Schuldnertypen', [
        'Privatperson mit hohen Schulden', 'Selbstständiger', 'Freiberufler',
        'GmbH-Geschäftsführer', 'Gesellschafter', 'Einzelkaufmann',
        'Ehepaar gemeinsam', 'Erbe mit überschuldetem Nachlass',
        'Bürge für Dritte', 'Unternehmer in der Krise',
        'Kleingewerbetreibender', 'Arzt oder Anwalt', 'Landwirt',
    ]],
    ['Gläubigergruppen und Schuldenarten', [
        'Hausbank und Kredit', 'Sparkasse', 'Finanzamt',
        'Sozialversicherung', 'Krankenversicherung', 'Lieferant', 'Vermieter',
        'Arbeitnehmer als Gläubiger', 'Leasinggeber',
        'Factoring-Unternehmen', 'Privatperson als Gläubiger',
        'Öffentlicher Auftraggeber',
    ]],
    ['Insolvenzauslöser und -situationen', [
        'Überschuldung durch Scheidung', 'Schulden durch Jobverlust',
        'Unternehmensscheitern', 'Schulden durch Krankheit',
        'Bürgschaft fällig geworden', 'Fehlinvestition',
        'Kredit nicht mehr bedienbar', 'Erbschaft mit Schulden übernommen',
        'Schadensersatz nach Unfall', 'Insolvenz des Arbeitgebers',
        'Fehler bei Unternehmensführung', 'Plötzlicher Einnahmenausfall',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 18: Markenrecht ──────────────────────────────────────────────────────────
$data[18] = [
    ['Markenrechtsinhaber', [
        'Einzelunternehmer', 'GmbH', 'AG', 'Startup', 'Kreativunternehmen',
        'Handwerksbetrieb', 'Restaurant und Gastronomie', 'Modemarke',
        'Technologieunternehmen', 'Verein', 'Gemeinnützige Organisation',
        'Lizenzgeber', 'Franchisegeber', 'Künstler oder Band',
        'YouTuber und Creator', 'Influencer',
    ]],
    ['Branchen mit Markenschutz', [
        'Mode und Bekleidung', 'Lebensmittel und Getränke',
        'Technologie und Software', 'Kosmetik und Pflege',
        'Gesundheit und Pharma', 'Automobilbranche', 'Sport und Fitness',
        'Gastronomie und Hotel', 'Handwerk und Dienstleistung',
        'Medien und Unterhaltung', 'E-Commerce', 'Bauwirtschaft',
        'Bildung', 'Verlagswesen',
    ]],
    ['Markenschutzprobleme', [
        'Verwechselbar ähnliche Marke angemeldet',
        'Markenplagiat und Fälschung', 'Domain-Grabbing',
        'Keyword-Advertising mit fremder Marke',
        'Markenverletzung auf Amazon oder eBay',
        'Gefälschte Produkte im Umlauf', 'Marke nicht verlängert',
        'Widerspruchsverfahren verloren', 'Parallelimport aus Drittland',
        'Markeneintragung abgelehnt', 'Abmahnung wegen Marke erhalten',
        'Marke im Ausland verletzt', 'Social-Media-Profil mit fremder Marke',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 19: Medizinrecht ─────────────────────────────────────────────────────────
$data[19] = [
    ['Patientengruppen', [
        'Privatpatient', 'Kassenpatient', 'Schwerkranker Patient',
        'Chronisch Kranker', 'Krebspatient', 'Chirurgischer Patient',
        'Geburtsfall Mutter', 'Notfallpatient', 'Psychiatrischer Patient',
        'Minderjähriger Patient', 'Älterer Patient über 70',
        'Patient mit Behinderung', 'Ausländischer Patient',
        'Pflegebedürftiger Patient', 'Onkologischer Patient',
    ]],
    ['Medizinische Fachrichtungen', [
        'Chirurgie und Orthopädie', 'Innere Medizin',
        'Gynäkologie und Geburtshilfe', 'Neurologie und Psychiatrie',
        'Herzchirurgie', 'Radiologie und Bildgebung', 'Zahnmedizin',
        'Dermatologie', 'Augenheilkunde', 'HNO',
        'Anästhesie und Intensivmedizin', 'Notaufnahme', 'Onkologie',
        'Pädiatrie', 'Urologie', 'Plastische Chirurgie',
    ]],
    ['Medizinrechtliche Problemsituationen', [
        'Operationsfehler', 'Falsche Diagnose gestellt',
        'Medikamentenfehler', 'Kein Aufklärungsgespräch',
        'Hygienemängel', 'Komplikation nicht erkannt', 'Narkosefehler',
        'Unzureichende Nachsorge', 'Geburtsfehler',
        'Unnötige Operation durchgeführt', 'Behandlung abgelehnt',
        'Medikament verweigert', 'Krankenhausinfekt', 'Wartezeitschaden',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 20: Mietrecht ────────────────────────────────────────────────────────────
$data[20] = [
    ['Mietparteien', [
        'Mieter in Privatwohnung', 'Vermieter privat', 'WG-Bewohner',
        'Untermieter', 'Mieter im Einfamilienhaus', 'Gewerberaummieter',
        'Garagenmieter', 'Ferienwohnungsmieter', 'Sozialwohnungsmieter',
        'Student im Wohnheim', 'Senior im Betreuten Wohnen',
        'Expat und Ausländer', 'Mieter mit Behinderung', 'Alleinerziehende',
        'Rentner als Mieter', 'Familie mit Kindern als Mieter',
    ]],
    ['Wohnungstypen und Immobilien', [
        'Wohnung im Mehrfamilienhaus', 'Einfamilienhaus',
        'Doppelhaushälfte', 'Reihenhaus', 'Penthouse',
        'Souterrainwohnung', 'Dachgeschosswohnung', 'Einzimmerwohnung',
        'WG-Zimmer', 'Möbliertes Zimmer', 'Gewerberaum', 'Ladenlokal',
        'Praxis und Büro', 'Ferienwohnung', 'Garage und Stellplatz',
        'Lagerraum',
    ]],
    ['Wohnlagen und Märkte', [
        'Großstadt Innenstadtlage', 'Großstadt Randlage',
        'Mittelgroße Stadt', 'Kleinstadt', 'Vorort', 'Dorf und Landlage',
        'Ballungsraum', 'Touristisches Gebiet', 'Universitätsstadt',
        'Sozialer Brennpunkt', 'Hauptstadtnähe', 'Grenzregion',
        'Teures Pflasterviertel', 'Studentenviertel',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 21: Migrationsrecht ──────────────────────────────────────────────────────
$data[21] = [
    ['Personengruppen nach Herkunft', [
        'EU-Bürger', 'Türkischer Staatsangehöriger', 'Indischer Facharbeiter',
        'Chinesischer Bürger', 'US-Amerikanischer Bürger', 'Russischer Bürger',
        'Ukrainischer Bürger', 'Brasilianischer Bürger',
        'Philippinischer Facharbeiter', 'Japanischer Bürger',
        'Südkoreanischer Bürger', 'Australischer Bürger', 'Schweizer Bürger',
        'Britischer Bürger post-Brexit', 'Mexikanischer Bürger',
        'Ägyptischer Bürger', 'Nigerianischer Bürger',
    ]],
    ['Aufenthaltszwecke', [
        'Arbeit als Facharbeiter', 'Arbeit als Hochqualifizierter',
        'Studium an Universität', 'Familiennachzug zum Ehepartner',
        'Familiennachzug zu Kindern', 'Selbstständigkeit und Unternehmen',
        'Au-Pair Aufenthalt', 'Sprachkurs', 'Freiwilligenarbeit',
        'Ruhestand in Deutschland', 'Kurzfristiger Aufenthalt',
        'Durchreise und Transit', 'Humanitäre Gründe',
        'Journalismus und Medien', 'Religiöse Tätigkeiten',
    ]],
    ['Aufenthaltsrechtliche Situationen', [
        'Visum abgelaufen', 'Aufenthaltserlaubnis verlängern',
        'Niederlassungserlaubnis beantragen', 'Blaue Karte EU anstreben',
        'Einbürgerung planen', 'Familiennachzug beantragen',
        'Arbeitserlaubnis fehlt', 'Ausländerbehörde verweigert',
        'Im Widerspruchsverfahren', 'Klage beim Verwaltungsgericht',
        'Abschiebung droht', 'Passkopie verloren', 'Sprachnachweis fehlt',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 22: Nachbarrecht ─────────────────────────────────────────────────────────
$data[22] = [
    ['Eigentümer und Nutzergruppen', [
        'Hauseigentümer Einfamilienhaus', 'Wohnungseigentümer',
        'Mieter mit Gartennutzung', 'Pächter', 'Grundstückseigentümer',
        'Erbbaurechtsinhaber', 'Vermieter', 'WEG-Eigentümer',
        'Ferienhausbesitzer', 'Schrebergartenbesitzer',
        'Gewerbebesitzer neben Wohngebiet',
    ]],
    ['Eigentumstypen und Lagen', [
        'Einfamilienhaus Nebeneinander', 'Doppelhaushälfte', 'Reihenhaus',
        'Grundstück mit Garten', 'Mehrfamilienhaus', 'Eigentumswohnung',
        'Feriengrundstück', 'Gewerbe neben Wohngebiet', 'Bauernhof',
        'Kleingarten und Schrebergarten', 'Grundstück an Bahnlinie',
        'Grundstück neben Gewerbegebiet',
    ]],
    ['Nachbarschaftliche Konflikte', [
        'Lärmbelästigung durch Musik', 'Grenzstreit und Überbau',
        'Baumschnitt zu kurz', 'Äste hängen über Grundstück',
        'Wurzeleinwuchs im Garten', 'Geruchsbelästigung',
        'Lichtverschmutzung', 'Bauprojekt des Nachbarn',
        'Überschwemmung durch Nachbar', 'Hunde und Katzen Streit',
        'Abfall und Müll', 'Parken auf privatem Gelände',
        'Überwachungskameras', 'Feuer und Grillrauch',
        'Winterdienst Streit', 'Hecke zu hoch', 'Baum sturmgefährdet',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 23: Patentrecht ──────────────────────────────────────────────────────────
$data[23] = [
    ['Patentanmelder und -inhaber', [
        'Einzelerfinder privat', 'Kleines Startup',
        'Mittelständisches Unternehmen', 'Konzern',
        'Hochschule und Forschungsinstitut', 'Arbeitnehmererfinder',
        'Forschungsgruppe', 'Ausländischer Patentinhaber',
        'Technologietransfer-Organisation', 'Spin-off der Universität',
    ]],
    ['Technologiebranchen', [
        'Maschinenbau', 'Elektrotechnik', 'Software und IT', 'Medizintechnik',
        'Biotechnologie', 'Chemie und Pharma', 'Automobilindustrie',
        'Luft- und Raumfahrt', 'Energie und Umwelt', 'Telekommunikation',
        'Nanotechnologie', 'Lebensmitteltechnologie', 'Textil und Mode',
        'Landwirtschaftstechnik', 'Robotik und Automation',
    ]],
    ['Patentrechtliche Situationen', [
        'Patent anmelden DE', 'Patent anmelden europäisch EPA',
        'Patent anmelden international PCT', 'Bestehendes Patent verteidigen',
        'Patentverletzung abwehren', 'Selbst fremdes Patent verletzt',
        'Arbeitnehmererfindung sichern', 'Lizenz verhandeln',
        'Patent übertragen oder verkaufen', 'Patent im Ausland schützen',
        'Gebrauchsmuster eintragen', 'Nichtigkeitsklage führen',
        'Zwangslizenz beantragen', 'Patent läuft ab verlängern',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 24: Pflegerecht ──────────────────────────────────────────────────────────
$data[24] = [
    ['Pflegebedürftige Personengruppen', [
        'Ältere Person ab 65', 'Hochbetagte über 80', 'Person mit Demenz',
        'Schlaganfallpatient', 'ALS-Patient', 'MS-Patient',
        'Person mit Körperbehinderung', 'Kind mit Pflegebedarf',
        'Junger Erwachsener mit Pflegebedarf', 'Terminal Kranker',
        'Person nach schwerem Unfall', 'Intensivpflegebedürftiger',
        'Person nach Schlaganfall', 'Person mit schwerer Herzerkrankung',
    ]],
    ['Pflegesituationen und -formen', [
        'Häusliche Pflege durch Familie allein',
        'Häusliche Pflege mit Pflegedienst', 'Vollstationäres Pflegeheim',
        'Tagespflege tagsüber', 'Kurzzeitpflege nach Klinik',
        'Betreutes Wohnen', 'Wohngemeinschaft für Pflegebedürftige',
        'Intensivpflege zu Hause', 'Pflege im Ausland', 'Rückkehr aus Reha',
        'Übergangspflege', 'Nachtpflege', 'Verhinderungspflege',
    ]],
    ['Pflegerechtliche Probleme', [
        'Pflegegrad zu niedrig eingestuft', 'Pflegegeld abgelehnt',
        'Pflegekassen-Leistung verweigert', 'Mängel im Pflegeheim',
        'Freiheitsentziehende Maßnahmen', 'Pflegevertrag kündigen wollen',
        'Heimkosten zu hoch', 'Sozialhilfe für Pflege',
        'Angehörige überlastet', 'Pflegeberatung fehlt',
        'Patientenverfügung missachtet', 'Betreuungsrecht unklar',
        'Pflegetagegeld Versicherung streitet', 'Qualität des Pflegedienstes',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 25: Reiserecht ───────────────────────────────────────────────────────────
$data[25] = [
    ['Reisende', [
        'Urlauber Pauschalreise', 'Geschäftsreisender',
        'Familie mit Kleinkindern', 'Senioren-Gruppe',
        'Student Abenteuerreise', 'Hochzeitsreise-Paar',
        'Gruppenreise Verein', 'Kreuzfahrtpassagier', 'Sportgruppe',
        'Schulklasse', 'Alleinreisende Frau', 'Rucksackreisender',
        'Luxusreisender',
    ]],
    ['Reisearten und Buchungsformen', [
        'Pauschalreise Veranstalter', 'Kreuzfahrt Vollpension',
        'Cluburlaub all-inclusive', 'Studienreise Kulturreise',
        'Abenteuer- und Aktivreise', 'Sprachreise Ausland',
        'Individualreise selbst gebucht', 'Fernreise interkontinental',
        'Europatrip Städtereise', 'Flugpaket mit Hotel',
        'Mietwagenreise Selbstfahrer', 'Campingreise',
        'Bahnreise mit Hotel', 'Busrundreise',
    ]],
    ['Reiseziele mit besonderem Risiko', [
        'Mallorca und Balearen', 'Türkische Riviera', 'Griechenland Inseln',
        'Ägypten am Roten Meer', 'Spanien Costa del Sol',
        'Portugal Algarve', 'Kroatien Dalmatien', 'Italien Gardasee',
        'Karibik Allgemein', 'USA und Florida', 'Mexiko Cancun',
        'Thailand und Bali', 'Malediven', 'Dubai Emirate',
        'Marokko und Tunesien', 'Kuba', 'Dominikanische Republik',
        'Kenia Safaris', 'Kanaren', 'Kapverdische Inseln',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 26: Schadensersatzrecht ──────────────────────────────────────────────────
$data[26] = [
    ['Geschädigte Personengruppen', [
        'Privatperson als Verkehrsopfer', 'Fußgänger und Radfahrer',
        'Kind als Unfallopfer', 'Arbeitnehmer nach Arbeitsunfall',
        'Patient nach Behandlungsfehler', 'Mieter nach Schaden',
        'Käufer nach Produktfehler', 'Tier als Opfer', 'Sportler nach Unfall',
        'Senior nach Sturz', 'Ausländer mit Unfallschaden',
    ]],
    ['Schadensursachen und -bereiche', [
        'Verkehrsunfall als Fahrer', 'Verkehrsunfall als Beifahrer',
        'Motorradunfall', 'Fahrradunfall', 'Fußgängerunfall', 'Arbeitsunfall',
        'Sturz in Supermarkt', 'Sturz auf Eis', 'Hundebiss Tierhalterhaftung',
        'Freizeitunfall', 'Ärztlicher Fehler', 'Handwerkerfehler',
        'Sachbeschädigung durch Dritte', 'Einbruch mit Schaden',
        'Feuer durch Fahrlässigkeit', 'Wasserrohrbruch Nachbar',
        'Produktfehler Hersteller',
    ]],
    ['Schadensarten und Umfang', [
        'Körperverletzung leicht', 'Körperverletzung schwer',
        'Dauerschaden und Behinderung', 'Sachschaden am Fahrzeug',
        'Sachschaden an Eigentum', 'Kurzfristiger Verdienstausfall',
        'Langfristiger Verdienstausfall', 'Haushaltsführungsschaden',
        'Nutzungsausfall Fahrzeug', 'Immaterieller Schaden',
        'Psychisches Trauma', 'Folgebehandlungskosten', 'Reha-Kosten',
        'Pflegekosten durch Unfall',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 27: Scheidungsrecht ──────────────────────────────────────────────────────
$data[27] = [
    ['Eheleute nach Familiensituation', [
        'Ehepaar mit minderjährigen Kindern', 'Ehepaar ohne Kinder',
        'Paar mit erwachsenen Kindern', 'Ehepaar mit Immobilienvermögen',
        'Kurze Ehe unter 5 Jahren', 'Lange Ehe über 20 Jahren',
        'Gutverdienender Hauptverdiener', 'Nicht berufstätiger Partner',
        'Selbstständiges Paar', 'Beamtenpaar', 'Binationaler Partner',
        'Zweitehe Scheidung', 'Eingetragene Partnerschaft',
    ]],
    ['Trennungsszenarien', [
        'Einvernehmliche Scheidung gewünscht', 'Streitige Scheidung drohend',
        'Mit häuslicher Gewalt', 'Untreue und Vertrauensbruch',
        'Einer zieht aus', 'Gemeinsame Wohnung strittig',
        'Bei Auslandsaufenthalt eines Partners',
        'Unterschiedliche Staatsangehörigkeit', 'Scheidung nach kurzer Ehe',
        'Mit Suchtproblem eines Partners', 'Mit psychischer Erkrankung',
        'Mit Spielschulden eines Partners',
    ]],
    ['Vermögenssituationen', [
        'Eigentumswohnung gemeinsam', 'Einfamilienhaus gemeinsam',
        'Unternehmen eines Partners', 'Hohe gemeinsame Schulden',
        'Hohe Ersparnisse', 'Betriebliche Altersvorsorge',
        'Privatrente und Lebensversicherung', 'Auslandskonten und Vermögen',
        'Erbschaft während Ehe', 'Voreheliches Vermögen',
        'Schenkung von Eltern', 'Gemeinschaftsunternehmen',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 28: Schmerzensgeldrecht ──────────────────────────────────────────────────
$data[28] = [
    ['Betroffene Opfer', [
        'Privatperson nach Unfall', 'Kind als Unfallopfer',
        'Senior nach Sturz', 'Sportler', 'Berufsfahrer',
        'Arbeitnehmer nach Unfall', 'Patient nach Fehler', 'Motorradfahrer',
        'Radfahrer', 'Fußgänger', 'Opfer eines Hundebisses',
        'Opfer von Körperverletzung', 'Tourist im Ausland verletzt',
        'Schwangere nach Unfall', 'Person mit Vorerkrankung',
    ]],
    ['Unfallursachen und Verursacher', [
        'Verkehrsunfall Schuld anderer PKW', 'Verkehrsunfall als Beifahrer',
        'Motorradunfall durch Dritte', 'Fahrradunfall durch PKW',
        'Fußgänger angefahren', 'Arbeitsunfall', 'Sturz in Supermarkt',
        'Sturz auf Eis auf öffentlichem Weg', 'Hundebiss durch Nachbar',
        'Sportschaden durch Gegner', 'Ärztlicher Fehler',
        'Körperverletzung durch Person', 'Produktfehler Hersteller',
        'Mobbing am Arbeitsplatz',
    ]],
    ['Verletzungsarten und Schwere', [
        'Leichte Verletzung schnell geheilt',
        'Mittelgradige Verletzung Monate',
        'Schwere Verletzung mit Dauerschaden', 'Teilbehinderung',
        'Vollbehinderung', 'Todesfolge', 'HWS-Schleudertrauma',
        'Knochenbrüche mehrfach', 'Hirnverletzung TBI',
        'Narbenbildung sichtbar', 'Organschaden dauerhaft',
        'Psychisches Trauma PTBS', 'Tinnitus durch Unfall', 'Sehverlust',
        'Gliedmaßenverlust',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 29: Schulrecht ───────────────────────────────────────────────────────────
$data[29] = [
    ['Betroffene Schulbeteiligte', [
        'Eltern von Grundschüler', 'Eltern von Gymnasiasten',
        'Eltern von Förderschüler', 'Eltern von Berufsschüler',
        'Schüler selbst (volljährig)', 'Lehrer in Konflikt',
        'Schulleitung', 'Schulpsychologe', 'Privatschule Träger',
    ]],
    ['Schulformen', [
        'Öffentliche Grundschule', 'Gymnasium', 'Realschule', 'Hauptschule',
        'Gesamtschule', 'Förderschule Sonderpädagogik', 'Berufsschule',
        'Privatschule konfessionell', 'Waldorfschule', 'Montessori-Schule',
        'Internationale Schule', 'Heimschule Internat', 'Online-Schule',
        'Fachoberschule',
    ]],
    ['Schulische Konflikte und Probleme', [
        'Schulwechsel erzwungen', 'Sitzenbleiben abgelehnt',
        'Schulverweis Schulausschluss', 'Noten ungerechtfertigt',
        'Mobbing unter Schülern', 'Cybermobbing',
        'Diskriminierung durch Lehrer', 'Schulpflicht-Ausnahme',
        'Religionsunterricht-Abmeldung', 'Sonderförderung verweigert',
        'Schulleistungstest angefochten', 'Schulwegunfall', 'Schulunfall',
        'Elternrecht übergangen', 'Prüfungswiederholung abgelehnt',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 30: Sozialrecht ──────────────────────────────────────────────────────────
$data[30] = [
    ['Sozialleistungsempfänger', [
        'Arbeitsloser Arbeitnehmer', 'Erwerbsgeminderter', 'Frührentner',
        'Rentner mit wenig Rente', 'Alleinerziehende',
        'Familie mit vielen Kindern', 'Pflegebedürftiger',
        'Person mit Behinderung', 'Wohnungsloser', 'Langzeitarbeitsloser',
        'Geringverdiener Aufstocker', 'Student in Not', 'Azubi in Not',
        'Migrant mit Sozialleistung',
    ]],
    ['Sozialleistungsarten', [
        'Bürgergeld ALG II', 'Arbeitslosengeld I ALG I', 'Krankengeld',
        'Erwerbsminderungsrente', 'Altersrente', 'Elterngeld und Elterngeld Plus',
        'Kindergeld', 'Wohngeld', 'Grundsicherung im Alter', 'BAföG',
        'Sozialhilfe', 'Blindengeld', 'Pflegegeld', 'Kinderkrankengeld',
        'Unterhaltsvorschuss', 'Kurzarbeitergeld',
    ]],
    ['Sozialbehördliche Probleme', [
        'Antrag abgelehnt', 'Leistung wurde gekürzt',
        'Aufhebungs- und Erstattungsbescheid', 'Sanktion und Streichung',
        'Zu lange Bearbeitungszeit', 'Falscher Bescheid',
        'Rückzahlung gefordert', 'Auskunft verweigert',
        'Übergangssituation nicht abgedeckt', 'Umzug verweigert',
        'Einkommen falsch angerechnet', 'Vermögen falsch angerechnet',
        'Eingliederungsvereinbarung strittig',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 31: Steuerrecht ──────────────────────────────────────────────────────────
$data[31] = [
    ['Steuerpflichtige Gruppen', [
        'Arbeitnehmer', 'Selbstständiger und Freiberufler',
        'GmbH-Gesellschafter', 'Kapitalanleger', 'Vermieter von Immobilien',
        'Rentner', 'Erbe mit Erbschaft', 'Auslandsrentner', 'Grenzgänger',
        'Expatriate', 'Gewerbetreibender', 'Landwirt',
        'Künstler und Sportler', 'Influencer und Creator',
    ]],
    ['Steuerarten', [
        'Einkommensteuer', 'Körperschaftsteuer', 'Gewerbesteuer',
        'Umsatzsteuer und MwSt', 'Erbschaft- und Schenkungsteuer',
        'Grundsteuer', 'Kapitalertragsteuer', 'Grunderwerbsteuer',
        'Kirchensteuer', 'Solidaritätszuschlag', 'Energiesteuer und Stromsteuer',
        'Lohnsteuer',
    ]],
    ['Steuerrechtliche Situationen', [
        'Steuerbescheid falsch', 'Einspruch nötig',
        'Betriebsprüfung angekündigt', 'Steuerklasse ändern',
        'Firmengründung Steuern', 'Immobilienkauf Steuern',
        'Auslandsimmobilien', 'Unternehmensverkauf Steuern',
        'Scheidung mit Steuerfolgen', 'Erbschaft und Steuern',
        'Investments und Kapitalertrag', 'Nebenberuf und Steuern',
        'Steuerparadies genutzt', 'Verluste steuerlich nutzen',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 32: Steuerstrafrecht ─────────────────────────────────────────────────────
$data[32] = [
    ['Beschuldigte Personengruppen', [
        'Selbstständiger Unternehmer', 'GmbH-Geschäftsführer',
        'Steuerberater', 'Buchhalter', 'Privatperson mit Kapitalvermögen',
        'Arzt mit Privatpraxis', 'Anwalt', 'Immobilieninvestor',
        'Auslandskonto-Inhaber', 'Prominenter', 'Unternehmenserbe',
        'Landwirt', 'Restaurantbesitzer',
    ]],
    ['Deliktsbereiche Steuern', [
        'Einkommensteuerhinterziehung', 'Umsatzsteuerbetrug',
        'Körperschaftsteuerhinterziehung', 'Schwarzarbeit und Steuern',
        'Offshore-Konten verschwiegen', 'Cum-Ex Transaktionen',
        'Lohnsteuerhinterziehung', 'Erbschaftsteuer-Hinterziehung',
        'Stiftungen missbraucht', 'Scheinrechnungen erstellt',
        'Kassenmanipulation', 'Falsche Betriebsausgaben',
        'Gewerbesteuer hinterzogen',
    ]],
    ['Verfahrensphasen Steuerstraf', [
        'Verdacht des Finanzamts', 'Steuerprüfung eingeleitet',
        'Strafbefehl erhalten', 'Selbstanzeige einreichen',
        'Durchsuchung der Geschäftsräume', 'Beschlagnahme von Unterlagen',
        'Ermittlungsverfahren läuft', 'Anklage zugelassen',
        'Hauptverhandlung', 'Urteil und Revision',
        'Einziehung von Vermögen', 'Berufsverbot droht',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 33: Strafrecht ───────────────────────────────────────────────────────────
$data[33] = [
    ['Beschuldigte und Angeklagte', [
        'Jugendlicher Täter 14-17', 'Heranwachsender 18-21',
        'Erwachsener Ersttäter', 'Rückfalltäter',
        'Ausländischer Beschuldigter', 'Inhaftierter Beschuldigter',
        'Beamter als Täter', 'Arzt als Täter', 'Lehrer als Täter',
        'Frau als Beschuldigte', 'Täter mit Suchtproblem',
        'Täter mit psychischer Erkrankung', 'Wirtschaftstäter',
    ]],
    ['Deliktsgruppen', [
        'Körperverletzungsdelikte', 'Diebstahl und Eigentumsdelikte',
        'Betrug und Täuschung', 'Sexualdelikte', 'Tötungsdelikte',
        'Drogendelikte', 'Computerstraftaten und Cybercrime',
        'Wirtschaftsstraftaten', 'Verkehrsdelikte', 'Häusliche Gewalt',
        'Urkundenfälschung', 'Nötigung und Bedrohung', 'Stalking',
        'Beleidigung und Verleumdung', 'Umweltstraftaten',
    ]],
    ['Verfahrenssituationen', [
        'Anzeige gerade erstattet', 'Vor Polizeianhörung',
        'Haftbefehl droht', 'In Untersuchungshaft',
        'Vor der Hauptverhandlung', 'Im laufenden Gerichtsverfahren',
        'Nach Verurteilung', 'Auf Bewährung', 'Revision einlegen',
        'Begnadigung beantragen', 'Strafregister löschen',
        'Mit Pflichtverteidiger', 'Mit Wahlverteidiger',
        'Im Auslieferungsverfahren',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 34: Unternehmenskrise ────────────────────────────────────────────────────
$data[34] = [
    ['Unternehmenstypen in der Krise', [
        'GmbH in der Krise', 'AG in der Krise', 'KG in der Krise',
        'Einzelunternehmen', 'Freiberufler', 'Handwerksbetrieb',
        'Restaurant und Gastronomie', 'Einzelhandelsgeschäft', 'IT-Startup',
        'Produktionsunternehmen', 'Dienstleistungsunternehmen',
        'Landwirtschaftlicher Betrieb', 'Pflegeeinrichtung', 'Bauunternehmen',
    ]],
    ['Krisenauslöser', [
        'Umsatzrückgang Marktveränderung', 'Verlust eines Großkunden',
        'Insolvenz eines Großschuldners', 'Corona-Folgekosten',
        'Energiepreisschock', 'Lieferkettenprobleme', 'Fachkräftemangel',
        'Digitalisierungsrückstand', 'Misswirtschaft', 'Gesellschafterstreit',
        'Betrug durch Mitarbeiter', 'Inflationsschock', 'Zinserhöhungen',
        'Wegfall von Fördermitteln',
    ]],
    ['Krisenstadien', [
        'Strategische Krise erkannt', 'Absatzkrise eingetreten',
        'Erfolgskrise mit Verlusten', 'Liquiditätsengpass akut',
        'Vor Überschuldung', 'Zahlungsunfähig',
        'Vorläufige Insolvenz beantragt', 'Insolvenzverfahren läuft',
        'Sanierung im Gange', 'Nach Insolvenz Neustart',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 35: Unternehmensrecht ────────────────────────────────────────────────────
$data[35] = [
    ['Unternehmensgrößen und -typen', [
        'Einzelunternehmer', 'Kleinstunternehmen 1-9 MA',
        'Kleines Unternehmen 10-49', 'Mittelständisches Unternehmen',
        'Großunternehmen', 'Konzern', 'Internationales Unternehmen',
        'Holding', 'Startup und Scaleup', 'Familienunternehmen',
        'Franchiseunternehmen', 'Soziales Unternehmen',
    ]],
    ['Branchen', [
        'Gastgewerbe und Gastronomie', 'Einzelhandel und Großhandel',
        'IT und Software', 'Baugewerbe', 'Gesundheitswesen',
        'Transport und Logistik', 'Industrie und Fertigung',
        'Kreativwirtschaft', 'Bildung und Training',
        'Finanzdienstleistungen', 'Immobilien', 'Energie und Umwelt',
        'Handwerk', 'Landwirtschaft', 'Tourismus',
    ]],
    ['Unternehmensrechtliche Situationen', [
        'Beim Unternehmen gründen', 'Nach erstem Umsatz steuerlich',
        'Beim Einstieg eines Investors', 'Bei einem Gesellschafterwechsel',
        'Bei einer Expansion ins Ausland', 'Beim Unternehmensverkauf',
        'Bei einer Fusion', 'Bei Eintritt in neue Märkte',
        'Bei Mitarbeiterbeteiligung ESOP', 'Beim Abschluss wichtiger Verträge',
        'Bei Unternehmensumstrukturierung', 'Bei Gewerbeanmeldung',
        'Bei WEG-Eigentum Unternehmen',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 36: Urheberrecht ─────────────────────────────────────────────────────────
$data[36] = [
    ['Rechteinhaber und Betroffene', [
        'Fotograf professionell', 'Fotograf Hobby', 'Musiker und Komponist',
        'Autor und Texter', 'Grafiker und Designer',
        'Webentwickler Programmierer', 'Filmregisseur',
        'YouTuber und Creator', 'Journalist', 'Wissenschaftler Forschung',
        'Architekt', 'Drehbuchautor', 'Gamer und Streamer', 'KI-Nutzer',
        'Verlag und Label',
    ]],
    ['Werkkategorien', [
        'Fotos und Bilder', 'Musik und Audiodateien', 'Texte und Artikel',
        'Software und Code', 'Filme und Videos', 'Kunst und Design',
        'Architektur und Bauwerke', 'Datenbanken', 'KI-generierte Inhalte',
        'Logos und Markengrafiken', 'Bücher und E-Books', 'Spiele und Apps',
        'Podcasts', 'Comics und Illustrationen',
    ]],
    ['Urheberrechtsverletzungen', [
        'Bild ohne Lizenz auf Website genutzt',
        'Musik ohne GEMA auf Veranstaltung',
        'Text copy-paste ohne Quellenangabe', 'Software geknackt und genutzt',
        'Video auf YouTube hochgeladen', 'In sozialen Medien ohne Credit',
        'Durch KI nachgeahmt oder generiert',
        'Im Newsletter ohne Erlaubnis',
        'Produktverpackung Design kopiert', 'Plagiat in Schule oder Uni',
        'Text-Scraping für KI', 'Foto von Veranstaltung genutzt',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 37: Versicherungsrecht ───────────────────────────────────────────────────
$data[37] = [
    ['Versicherungsnehmer', [
        'Privatperson Haushalt', 'Selbstständiger Unternehmer', 'GmbH',
        'Verein', 'Landwirt', 'Vermieter', 'Motorradfahrer', 'Hausbesitzer',
        'Hundehalter', 'Pferdehalter', 'Sportler Sportverein', 'Senior ab 65',
        'Familie mit Kindern', 'Arzt mit Berufshaftpflicht',
        'Anwalt Berufshaftpflicht',
    ]],
    ['Versicherungsarten', [
        'Kfz-Haftpflicht', 'Hausratversicherung', 'Gebäudeversicherung',
        'Private Haftpflichtversicherung', 'Berufsunfähigkeitsversicherung',
        'Lebensversicherung', 'Unfallversicherung', 'Rechtsschutzversicherung',
        'Private Krankenversicherung', 'Reiseversicherung Reiserücktritt',
        'Tierkrankenversicherung', 'Betriebshaftpflicht', 'D&O Versicherung',
        'Elementarschadenversicherung', 'Cyberversicherung',
    ]],
    ['Versicherungsrechtliche Probleme', [
        'Schadensregulierung abgelehnt', 'Zu geringe Entschädigungssumme',
        'Versicherung kündigt Vertrag', 'Prämie stark erhöht',
        'Obliegenheitsverletzung vorgeworfen', 'Vorsatz unterstellt',
        'Betrugsvorwurf gegen Versicherungsnehmer',
        'Schaden nicht vollständig ersetzt', 'Nach Naturkatastrophe Streit',
        'Nach Brand nicht reguliert', 'Nach Einbruch nicht bezahlt',
        'Widerruf des Versicherungsvertrags', 'Ausschlussklausel angewendet',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 38: Vertragsrecht ────────────────────────────────────────────────────────
$data[38] = [
    ['Vertragsparteien', [
        'Privatperson als Käufer', 'Privatperson als Verkäufer',
        'Unternehmer', 'Verbraucher', 'GmbH', 'Freiberufler', 'Verein',
        'Öffentliche Stelle', 'Franchisegeber', 'Franchisenehmer',
        'Lieferant', 'Auftraggeber', 'Dienstleister', 'Handwerker', 'Makler',
    ]],
    ['Vertragsarten', [
        'Kaufvertrag für Waren', 'Werkvertrag für Handwerk',
        'Dienstvertrag Arbeit', 'Grundstückskaufvertrag',
        'Autokauf und Fahrzeug', 'Leasingvertrag', 'Mietvertrag',
        'Darlehensvertrag', 'Franchise-Vertrag', 'IT-Vertrag und Software',
        'Berater- und Beratungsvertrag', 'Handelsvertretervertrag',
        'Abonnement und Abo', 'Versicherungsvertrag', 'Spendenvertrag',
    ]],
    ['Vertragliche Probleme', [
        'Vertrag widerrufen wollen', 'Gewährleistung einfordern',
        'Vertrag kündigen', 'Preiserhöhung ablehnen', 'Mangel reklamieren',
        'Lieferung nicht angekommen', 'Falsche Ware geliefert',
        'Zu spät geliefert', 'Zahlung verweigert', 'AGB-Klausel unwirksam',
        'Vertragsstrafe angedroht', 'Vertrag angefochten',
        'Betrug durch Vertragspartner', 'Unmöglichkeit der Leistung',
        'Rücktritt vom Vertrag',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 39: Verwaltungsrecht ─────────────────────────────────────────────────────
$data[39] = [
    ['Antragsteller und Betroffene', [
        'Privatperson gegen Behörde', 'Unternehmer gegen Ordnungsamt',
        'Ausländer gegen Ausländerbehörde', 'Bauherr gegen Bauamt',
        'Gewerbeinhaber gegen Gewerbeamt',
        'Autofahrer gegen Führerscheinstelle', 'Landwirt gegen Amt',
        'Student gegen Hochschule', 'Arzt gegen Ärztekammer',
        'Jagdscheininhaber', 'Waffenbesitzer', 'Gastwirt',
    ]],
    ['Behördentypen', [
        'Bauamt und Baurechtsbehörde', 'Ausländerbehörde', 'Ordnungsamt',
        'Straßenverkehrsamt', 'Gewerbeamt', 'Finanzamt', 'Schulamt',
        'Sozialamt', 'Gesundheitsamt', 'Umweltamt', 'Planungsbehörde',
        'Bundesministerium', 'Landesministerium', 'Regierungspräsidium',
        'Kommunale Behörde', 'Berufsgenossenschaft',
    ]],
    ['Verwaltungsrechtliche Konflikte', [
        'Antrag ohne Begründung abgelehnt', 'Bußgeld zu Unrecht auferlegt',
        'Genehmigung verweigert', 'Auflagen zu streng',
        'Verwaltungsakt fehlerhaft', 'Ermessen falsch ausgeübt',
        'Untätigkeit der Behörde', 'Gleichheitsgebot verletzt',
        'Daten falsch verarbeitet', 'Berufszulassung verweigert',
        'Lizenz entzogen', 'Subvention abgelehnt',
        'Zwangsgeld angedroht', 'Berufsverbot verhängt',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 40: Wirtschaftsrecht ─────────────────────────────────────────────────────
$data[40] = [
    ['Wirtschaftliche Akteure', [
        'Startup-Gründer', 'Mittelständisches Unternehmen', 'Konzern',
        'Ausländisches Unternehmen in DE', 'Importeur und Exporteur',
        'Bieter bei öffentlicher Ausschreibung', 'Großhändler',
        'Einzelhändler', 'Finanzinstitut', 'Beratungsunternehmen',
        'Franchise-Nehmer', 'Handelsvertreter', 'Genossenschaft',
    ]],
    ['Wirtschaftsrechtliche Bereiche', [
        'Kartell- und Wettbewerbsrecht', 'Vergabe- und Ausschreibungsrecht',
        'Außenhandels- und Exportrecht', 'Handelsrecht und HGB',
        'Kapitalmarktrecht', 'Gesellschaftsrecht international',
        'Verbraucherschutzrecht', 'Wirtschaftsstrafrecht',
        'Datenschutz Wirtschaft', 'Lieferkettensorgfalt',
        'Marktmissbrauchsrecht', 'Insolvenzrecht Wirtschaft',
    ]],
    ['Wirtschaftsrechtliche Situationen', [
        'Marktbeherrschung festgestellt', 'Kartellabsprache vorgeworfen',
        'Vergaberechtsverstoss', 'Exportkontrolle verletzt',
        'Embargoverstoß', 'Falschdarstellung im Geschäft',
        'Irreführende Werbung', 'Preisabsprache vorgeworfen',
        'Wettbewerbswidriges Verhalten', 'Subventionsmissbrauch',
        'Insolvenzanfechtung',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 41: Arbeitsrecht ─────────────────────────────────────────────────────────
$data[41] = [
    ['Arbeitnehmergruppen', [
        'Vollzeitarbeitnehmer', 'Teilzeitarbeitnehmer', 'Minijobber 520 Euro',
        'Midijobber', 'Auszubildender', 'Praktikant', 'Werkstudent',
        'Leiharbeiter', 'Freelancer', 'Heimarbeiter',
        'Telearbeiter Homeoffice', 'Befristet Beschäftigter',
        'Rentner im Minijob', 'Schwerbehinderter Arbeitnehmer',
        'Betriebsratsmitglied', 'Schwangere Arbeitnehmerin',
        'Arbeitnehmer in Elternzeit',
    ]],
    ['Branchen', [
        'Gesundheit und Pflege', 'IT und Software', 'Einzelhandel',
        'Industrie und Fertigung', 'Baugewerbe', 'Gastronomie und Hotellerie',
        'Transport und Spedition', 'Öffentlicher Dienst',
        'Finanz und Versicherung', 'Bildung und Wissenschaft', 'Handwerk',
        'Medien und Marketing', 'Pharma und Chemie', 'Sozialwirtschaft',
        'Landwirtschaft', 'Automobilindustrie', 'Handel und Vertrieb',
    ]],
    ['Arbeitssituationen', [
        'In der Probezeit', 'Während Krankheit', 'Nach langer Krankheit',
        'Während Schwangerschaft', 'Während Elternzeit',
        'Nach Elternzeit Rückkehr', 'Bei Betriebsübergang',
        'Bei Unternehmensinsolvenz', 'Mit Schwerbehinderung',
        'Im Homeoffice-Konflikt', 'Bei Betriebsratsmitgliedschaft',
        'Bei Tarifwechsel', 'Bei Unternehmensumstrukturierung',
        'Bei Betriebsschließung', 'Bei Massenentlassung',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 42: Arzthaftungsrecht ────────────────────────────────────────────────────
$data[42] = [
    ['Patientengruppen', [
        'Privatpatient', 'Kassenpatient', 'Kind als Patient',
        'Schwangere Mutter', 'Rentner als Patient', 'Chronisch Kranker',
        'Notfallpatient', 'Patient nach elektiver OP', 'Intensivpatient',
        'Psychiatrischer Patient', 'Patient mit Behinderung',
        'Ausländischer Patient', 'Pflegeheimbewohner',
    ]],
    ['Medizinische Behandlungsarten', [
        'Chirurgischer Eingriff', 'Diagnostische Untersuchung',
        'Medikamentöse Behandlung', 'Geburtshilflicher Eingriff',
        'Strahlentherapie', 'Chemotherapie', 'Orthopädische Behandlung',
        'Zahnärztliche Behandlung', 'Augenoperation', 'Herzoperation',
        'Neurochirurgischer Eingriff', 'Endoskopie',
        'Rehabilitationsbehandlung',
    ]],
    ['Haftungsauslösende Schadenssituationen', [
        'Behandlungsfehler bei Routinegriff',
        'Diagnosefehler verschlimmert Lage',
        'Aufklärung nicht ausreichend', 'Narkosefehler',
        'Komplikation nicht erkannt', 'Nachbehandlung unzureichend',
        'Im Krankenhaus Infektion übertragen',
        'Falsche Medikamentendosis', 'Unnötiger Eingriff durchgeführt',
        'Komplikation zu spät behandelt', 'Abgelehnte Behandlung',
        'Überweisung vergessen',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 43: Arztrecht ────────────────────────────────────────────────────────────
$data[43] = [
    ['Ärzte und Heilberufe', [
        'Niedergelassener Hausarzt', 'Facharzt eigene Praxis',
        'Krankenhausarzt angestellt', 'MVZ-Arzt', 'Zahnarzt',
        'Physiotherapeut', 'Heilpraktiker', 'Psychotherapeut', 'Tierarzt',
        'Belegarzt', 'Gutachter', 'Angestellter Arzt', 'Praxisinhaber',
        'Assistenzarzt Weiterbildung', 'Oberarzt Krankenhaus',
    ]],
    ['Medizinische Fachrichtungen', [
        'Allgemeinmedizin', 'Chirurgie', 'Gynäkologie', 'Kardiologie',
        'Orthopädie', 'Neurologie', 'Psychiatrie und Psychotherapie',
        'Radiologie', 'Urologie', 'Dermatologie', 'Augenheilkunde', 'HNO',
        'Innere Medizin', 'Pädiatrie', 'Anästhesie', 'Sportmedizin',
    ]],
    ['Berufsrechtliche Situationen', [
        'Praxisgründung planen', 'Praxisübernahme',
        'Niederlassung als Vertragsarzt', 'Anstellung im Krankenhaus',
        'Kooperation und MVZ-Beitritt', 'Praxisverkauf und Nachfolge',
        'Zulassungsentzug droht', 'Berufsrechtliches Verfahren',
        'Abrechnungsprüfung', 'Dienstverträge gestalten', 'Praxisauflösung',
        'Berufsunfähigkeit', 'Renteneintritt',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 44: Familienrecht ────────────────────────────────────────────────────────
$data[44] = [
    ['Familiensituationen und Personengruppen', [
        'Verheiratetes Paar mit Kleinkindern',
        'Verheiratetes Paar mit Schulkindern',
        'Unverheiratetes Elternpaar', 'Alleinerziehende Mutter',
        'Alleinerziehender Vater', 'Patchworkfamilie',
        'Gleichgeschlechtliches Paar', 'Paar in Trennung',
        'Paar nach Scheidung', 'Pflegefamilie', 'Adoptionsfamilie',
        'Stieffamilie', 'Großeltern als Erziehende',
    ]],
    ['Familiäre Lebensphasen', [
        'Beim Heiraten und Ehevertrag', 'Während der gemeinsamen Ehe',
        'Bei Geburt des ersten Kindes', 'Mit Kleinkindern unter 6',
        'Mit Schulkindern', 'Bei Trennung vom Partner', 'Bei der Scheidung',
        'Nach der Scheidung Alltag', 'Bei Wiederheiraten',
        'Im Rentenalter Ehegatten', 'Bei Pflegebedarf der Eltern',
        'Bei Todesfall in Familie',
    ]],
    ['Familiäre Konflikte und besondere Situationen', [
        'Mit erheblichem Einkommensunterschied',
        'Mit eigenem Unternehmen', 'Mit Auslandsberührung binational',
        'Bei häuslicher Gewalt', 'Bei Suchterkrankung eines Partners',
        'Bei psychischer Erkrankung', 'Bei sehr unterschiedlichen Religionen',
        'Bei Wohnortwechsel ins Ausland', 'Mit Erbschaft während Ehe',
        'Mit Schulden eines Partners', 'Bei Kindesentführung',
        'Bei Sorgerechtsstreit',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 45: Immobilienrecht ──────────────────────────────────────────────────────
$data[45] = [
    ['Käufer und Eigentümer', [
        'Privatperson Erstkäufer', 'Privatperson Kapitalanleger',
        'Ehepaar kauft gemeinsam', 'Erbengemeinschaft',
        'Unternehmen als Käufer', 'Bauträger', 'Immobilienfonds',
        'Ausländischer Käufer', 'Rentner Immobilienvermögen',
        'Scheidendes Ehepaar', 'WEG-Mitglieder', 'Selbstnutzer', 'Vermieter',
    ]],
    ['Immobilientypen', [
        'Eigentumswohnung', 'Einfamilienhaus',
        'Mehrfamilienhaus als Investment', 'Doppelhaus oder Reihenhaus',
        'Gewerbeimmobilie Einzelhandel', 'Bürogebäude',
        'Wohn- und Geschäftshaus', 'Ferienimmobilie', 'Baugrundstück',
        'Landwirtschaftliche Fläche', 'Denkmalschutzimmobilie',
        'Tiefgarage und Stellplätze', 'Industrieimmobilie',
        'Logistikimmobilie',
    ]],
    ['Immobilienrechtliche Situationen', [
        'Vor dem Kauf Due Diligence', 'Beim Kaufvertrag Notar',
        'Nach dem Kauf Mängel gefunden', 'Bei Bauproblemen beim Neubau',
        'Beim Vermieten Rechte', 'Bei Mietstreitigkeiten',
        'Beim Verkauf Pflichten', 'Bei Erbschaft einer Immobilie',
        'Bei Scheidung Immobilien', 'Bei Zwangsversteigerung',
        'Bei WEG-Streit Verwaltung', 'Mit Makler-Courtage-Streit',
        'Bei Grundbuchproblemen', 'Mit Baulast im Grundbuch',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ── 46: Verkehrsrecht ────────────────────────────────────────────────────────
$data[46] = [
    ['Fahrzeughalter und Fahrer', [
        'Privatperson PKW-Fahrer', 'Berufskraftfahrer LKW', 'Taxifahrer',
        'Motorradfahrer', 'Mopedfahrer', 'Fahrradfahrer', 'E-Scooter-Nutzer',
        'Busfahrer', 'Lieferdienstfahrer', 'Firmenwagenfahrer',
        'Fahranfänger Probezeit', 'Älterer Fahrer', 'Ausländischer Fahrer',
        'Fahrlehrer', 'Gefahrgutfahrer',
    ]],
    ['Fahrzeugtypen', [
        'PKW', 'LKW bis 3,5t', 'LKW über 7,5t', 'Motorrad', 'Mofa und Moped',
        'Bus und Reisebus', 'Transporter', 'Camper und Wohnmobil', 'E-Auto',
        'Hybrid-PKW', 'E-Scooter', 'Fahrrad und Pedelec', 'Quad und Trike',
        'Oldtimer', 'Anhänger und Gespann', 'Traktor und Landmaschine',
    ]],
    ['Verkehrsrechtliche Situationen', [
        'Auf der Autobahn', 'In der Innenstadt', 'In der 30er-Zone',
        'Im Baustellenbereich', 'Im Kreisverkehr', 'An der Ampel',
        'Im Parkhaus', 'Auf dem Parkplatz', 'Bei Dunkelheit und schlechter Sicht',
        'Bei Glatteis und Schnee', 'Im Ausland EU',
        'Im Ausland außereuropäisch', 'Beim Abbiegen', 'Im Stau',
        'Mit schwerem Anhänger', 'Beim Überholen', 'Auf der Landstraße',
    ]],
    ['Städte', $CITIES],
    ['Generelle Informationen', $GENERELLE],
];

// ─── Database Operations ──────────────────────────────────────────────────────

try {
    // Disable FK checks and truncate
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE variation_values');
    $pdo->exec('TRUNCATE TABLE variation_types');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "Tables truncated.\n\n";

    $stmtType = $pdo->prepare(
        'INSERT INTO variation_types (rechtsgebiet_id, rechtsfrage_id, name, slug) VALUES (?, NULL, ?, ?)'
    );
    $stmtValue = $pdo->prepare(
        'INSERT IGNORE INTO variation_values (variation_type_id, value, slug, tier) VALUES (?, ?, ?, 1)'
    );

    $totalTypes  = 0;
    $totalValues = 0;

    foreach ($data as $rgId => $types) {
        echo "── RG {$rgId} ──────────────────────────────────────\n";
        foreach ($types as [$typeName, $values]) {
            $typeSlug = slug($typeName);
            $stmtType->execute([$rgId, $typeName, $typeSlug]);
            $typeId = (int) $pdo->lastInsertId();
            $totalTypes++;

            $seenSlugs   = [];
            $insertedCount = 0;

            foreach ($values as $value) {
                $valueSlug = slug($value);
                if (isset($seenSlugs[$valueSlug])) {
                    echo "  [SKIP duplicate slug] {$value} ({$valueSlug})\n";
                    continue;
                }
                $seenSlugs[$valueSlug] = true;
                $stmtValue->execute([$typeId, $value, $valueSlug]);
                $insertedCount++;
                $totalValues++;
            }

            echo "  {$typeName}: {$insertedCount} values\n";
        }
        echo "\n";
    }

    echo "════════════════════════════════════════════════\n";
    echo "Done. Total types: {$totalTypes}, total values: {$totalValues}\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
