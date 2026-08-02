<?php
// Approximate county centroids (county HQ town coords) used as a fallback
// map location when a device/project does not report real GPS lat/lng.
function arise_approx_centroid(?string $county): array {
    static $c = [
        'Mombasa'=>[-4.0435,39.6682], 'Kwale'=>[-4.1747,39.4521], 'Kilifi'=>[-3.5107,39.9093],
        'Tana River'=>[-1.0167,40.1167], 'Lamu'=>[-2.2717,40.9020], 'Taita-Taveta'=>[-3.3963,38.3554],
        'Garissa'=>[-0.4569,39.6583], 'Wajir'=>[1.7471,40.0629], 'Mandera'=>[3.9366,41.8550],
        'Marsabit'=>[2.3344,37.9899], 'Isiolo'=>[0.3556,37.5833], 'Meru'=>[0.0470,37.6559],
        'Tharaka-Nithi'=>[-0.2971,37.7614], 'Embu'=>[-0.5310,37.4575], 'Kitui'=>[-1.3667,38.0167],
        'Machakos'=>[-1.5177,37.2634], 'Makueni'=>[-1.8039,37.6212], 'Nyandarua'=>[-0.1833,36.5000],
        'Nyeri'=>[-0.4201,36.9476], 'Kirinyaga'=>[-0.6591,37.3823], "Murang'a"=>[-0.7167,37.1500],
        'Kiambu'=>[-1.1714,36.8356], 'Turkana'=>[3.1167,35.6000], 'West Pokot'=>[1.6167,35.3833],
        'Samburu'=>[1.1000,36.6997], 'Trans Nzoia'=>[1.0167,34.9500], 'Uasin Gishu'=>[0.5167,35.2833],
        'Elgeyo-Marakwet'=>[0.8000,35.4833], 'Nandi'=>[0.1833,35.1167], 'Baringo'=>[0.4667,35.9667],
        'Laikipia'=>[0.0167,37.0667], 'Nakuru'=>[-0.3031,36.0800], 'Narok'=>[-1.0833,35.8667],
        'Kajiado'=>[-1.8500,36.7833], 'Kericho'=>[-0.3667,35.2833], 'Bomet'=>[-0.7833,35.3417],
        'Kakamega'=>[0.2833,34.7500], 'Vihiga'=>[0.0833,34.7167], 'Bungoma'=>[0.5667,34.5667],
        'Busia'=>[0.4667,34.1167], 'Siaya'=>[0.0667,34.2833], 'Kisumu'=>[-0.1022,34.7617],
        'Homa Bay'=>[-0.5273,34.4571], 'Migori'=>[-1.0634,34.4731], 'Kisii'=>[-0.6773,34.7796],
        'Nyamira'=>[-0.5633,34.9358], 'Nairobi'=>[-1.2921,36.8219],
    ];
    $county = trim((string)$county);
    if ($county !== '' && isset($c[$county])) return $c[$county];
    return [0.0236, 37.9062]; // Kenya national centroid fallback
}
