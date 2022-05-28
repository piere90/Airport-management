<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppController extends Controller
{
    public function index() {
        $aereoporti = DB::table('airports')->get();
        $voli = DB::table('flight')->get();
        $aereoporti = json_decode(json_encode($aereoporti), true);
        $voli = json_decode(json_encode($voli), true);

        return view('welcome', ['airports' => $aereoporti, 'flights' => $voli]);
    }

    public function store(Request $request) {

        $aereoporti = DB::table('airports')->get();
        $voli = DB::table('flight')->get();

        $aereoporti = json_decode(json_encode($aereoporti), true);
        $voli = json_decode(json_encode($voli), true);

        $arrayRequest = $request->toArray();

        foreach ($aereoporti as $item) {
            if($item['code'] == $arrayRequest['andata']) {
                $aereoporto1 = $item;
            }
            if($item['code'] == $arrayRequest['ritorno']) {
                $aereoporto2 = $item;
            }
        }

        $payload = $this->getFlighWithCheapPrice($aereoporto1,$aereoporto2, $aereoporti, $voli);

        if (is_array($payload)) {
//            dd($payload);
            $voloPrezzoBassoTrattaPrimaria = $payload['volo_tratta_primaria'];
            $voloPrezzoBassoTrattaSecondaria = $payload['volo_tratta_secondaria'];
            $voloPrezzoFinale = $payload['prezzo_finale_volo'];

            $aereoportoInizio = $this->getAirport($voloPrezzoBassoTrattaPrimaria['code_departure'], $aereoporti);
            $aereoportoDiScalo = $this->getAirport($voloPrezzoBassoTrattaPrimaria['code_arrival'], $aereoporti);
            $aereoportoFine = $this->getAirport($voloPrezzoBassoTrattaSecondaria['code_arrival'], $aereoporti);

//            $output = ['text_response' => '', 'aereoporto_inizio' => '', 'aereoporto_di_scalo' => '', 'aereoporto_fine' => ''];

            $output['aereoporto_inizio'] = $aereoportoInizio;
            $output['aereporto_di_scalo'] = $aereoportoDiScalo;
            $output['aereoporto_fine'] = $aereoportoFine;
            $output['volo_tratta_primaria'] = $payload['volo_tratta_primaria'];
            $output['volo_tratta_secondaria'] = $payload['volo_tratta_secondaria'];
            $output['text_response'] = "Il volo con uno scalo più economico ha un prezzo di " . $voloPrezzoFinale . "€ <br>";
            $output['text_response'] .= "Il volo è diviso in due tratte <br>";
            $output['text_response'] .= "La prima tratta presso " . $aereoportoInizio['name'] . " con atterraggio presso " . $aereoportoDiScalo['name'] . "<br>";
            $output['text_response'] .= "La seconda tratta presso " . $aereoportoDiScalo['name'] . " con atterraggio presso " . $aereoportoFine['name'] . "<br>";
            $output['text_response'] .= "Buon viaggio!";
        } else if ($payload == "no_scalo") {
            $output['text_response'] = "Questo volo non ha uno scalo, è un volo diretto";
        } else {
            $output['text_response'] = "Non sono disponibili voli con queste soluzioni";

        }

        return $output;
    }

    public function getFlighWithCheapPrice($aereoporto1, $aereoporto2, $aereoporti, $voli) {
        $latitudineAereporto1 = $aereoporto1['lat'];
        $longitudineAereporto1 = $aereoporto1['lng'];
        $latitudineAereporto2 = $aereoporto2['lat'];
        $longitudineAereporto2 = $aereoporto2['lng'];
        $distanzaTraAereoportiDiPartenza = $this->getDistanceBetweenPointsNew($latitudineAereporto1,$longitudineAereporto1,$latitudineAereporto2,$longitudineAereporto2);

        unset($aereoporti[array_search($aereoporto1, $aereoporti)]);
        unset($aereoporti[array_search($aereoporto2, $aereoporti)]);

        $aereportoDiScalo = null;
        foreach ($aereoporti as $aereoporto) {

            $distanzaTraAereoporti = $this->getDistanceBetweenPointsNew($latitudineAereporto1,$longitudineAereporto1,$aereoporto['lat'],$aereoporto['lng']);

            if ($distanzaTraAereoporti < $distanzaTraAereoportiDiPartenza) {
                $aereportoDiScalo = $aereoporto;
                break;
            }
        }

        if (is_null($aereportoDiScalo)) {
            return $result = "no_scalo";
        }

        $codiceAereoporto1 = $aereoporto1['code'];
        $codiceAereoporto2 = $aereoporto2['code'];
        $codiceAereoporto3 = $aereportoDiScalo['code'];

        $voliTrattaPrimaria = [];
        $voliTrattaSecondaria = [];
        foreach ($voli as $volo) {
            $voloCodicePartenza = $volo['code_departure'];
            $voloCodiceArrivo = $volo['code_arrival'];

            if ($voloCodicePartenza === $codiceAereoporto1 && $voloCodiceArrivo === $codiceAereoporto3) {
                $voliTrattaPrimaria[] = $volo;
            }
            if ($voloCodicePartenza === $codiceAereoporto3 && $voloCodiceArrivo === $codiceAereoporto2) {
                $voliTrattaSecondaria[] = $volo;
            }
        }

        if (!empty($voliTrattaPrimaria) && !empty($voliTrattaSecondaria)) {
            $voloPrezzoBassoTrattaPrimaria = $this->getPriceCheap($voliTrattaPrimaria);
            $voloPrezzoBassoTrattaSecondaria = $this->getPriceCheap($voliTrattaSecondaria);

//            dd($voliTrattaSecondaria);

            $voloPrezzoFinale = $this->sumPricesFlighs($voloPrezzoBassoTrattaPrimaria, $voloPrezzoBassoTrattaSecondaria);

            $result = [
                'prezzo_finale_volo'=>$voloPrezzoFinale,
                'volo_tratta_primaria'=>$voloPrezzoBassoTrattaPrimaria,
                'volo_tratta_secondaria'=>$voloPrezzoBassoTrattaSecondaria,
            ];
            return $result;
        } else {
            return false;
        }
    }

    public function getDistanceBetweenPointsNew($latitude1, $longitude1, $latitude2, $longitude2, $unit = 'kilometers') {
        $theta = $longitude1 - $longitude2;
        $distance = (sin(deg2rad($latitude1)) * sin(deg2rad($latitude2))) + (cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * cos(deg2rad($theta)));
        $distance = acos($distance);
        $distance = rad2deg($distance);
        $distance = $distance * 60 * 1.1515;
        switch($unit) {
            case 'miles':
                break;
            case 'kilometers' :
                $distance = $distance * 1.609344;
        }
        return (round($distance,2));
    }

    public function getPriceCheap($voli) {
        $result = null;
        $tempVoloPrice = null;
        foreach ($voli as $volo) {
            $voloPrice = (int)$volo['price'];

            if (is_null($tempVoloPrice)) {
                $tempVoloPrice = $voloPrice;
            }
            if ($voloPrice <= $tempVoloPrice) {
                $tempVoloPrice = $voloPrice;
                $result = $volo;
            }
        }
        return $result;
    }

    public function sumPricesFlighs($volo1, $volo2) {
        $prezzoFinaleVolo = (int)$volo1['price'] + (int)$volo2['price'];
        return (string)$prezzoFinaleVolo;
    }

    public function getAirport($codiceAeroporto, $aereoporti) {
        $result = null;
        foreach ($aereoporti as $aeroporto) {
            if ($codiceAeroporto === $aeroporto['code']) {
                $result = $aeroporto;
                break;
            }
        }
        return $result;
    }
}
