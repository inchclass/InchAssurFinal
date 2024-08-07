<?php

namespace Modules\Paiements\Controllers;

// require_once '..\ThirdParty\monetbil-php-master\monetbil.php';
require_once ROOTPATH . 'Modules\Paiements\ThirdParty\monetbil-php-master\monetbil.php';

use App\Traits\ControllerUtilsTrait;
use App\Traits\ErrorsDataTrait;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Assurances\Entities\SouscriptionsEntity;
use Modules\Assurances\Models\SouscriptionsModel;
use Modules\Consultations\Entities\AvisExpertEntity;
use Modules\Consultations\Entities\ConsultationEntity;
use Modules\Paiements\Entities\LignetransactionEntity;
use Modules\Paiements\Entities\PaiementEntity;
use Modules\Paiements\Entities\PayOptionEntity;
use Modules\Paiements\Entities\TransactionEntity;
use Modules\Paiements\Models\PaiementModesModel;
use Monetbil;
use PhpParser\Node\Stmt\Switch_;
use preload;

// use Modules\Paiements\ThirdParty\monetbil_php_master\monetbil as Monetbil;
// use \Monetbil;

class PaiementsController extends ResourceController
{
    use ControllerUtilsTrait;
    use ResponseTrait;
    use ErrorsDataTrait;

    protected $helpers = ['Modules\Documents\Documents', 'Modules\Images\Images', 'text'];

    public function index($identifier = null)
    {
        if ($identifier) {
            if (!auth()->user()->inGroup('administrateur')) {
                $response = [
                    'statut' => 'no',
                    'message' => 'Action non authorisée pour ce profil.',
                ];
                return $this->sendResponse($response, ResponseInterface::HTTP_UNAUTHORIZED);
            }
            $identifier = $this->getIdentifier($identifier, 'id');
            $utilisateur = model("UtilisateursModel")->where($identifier['name'], $identifier['value'])->first();
        } else {
            $utilisateur = $this->request->utilisateur;
        }
        $paiements = model("PaiementsModel")->where("auteur_id", $utilisateur->id)
            ->orderBy('dateCreation', 'desc')
            ->findAll();

        $response = [
            'statut'  => 'ok',
            'message' => count($paiements) . ' paiement(s) trouvée(s)',
            'data'    => $paiements,
        ];
        return $this->sendResponse($response);
    }

    public function applyAssurReduction()
    {   /* NB: Une réduction ne peut être appliquée que sur les produits de son auteur */
        /*
            - On recupère le cout de la souscription,
            - On compare avec le prix reçu, ils doivent être pareils.
            - On récupère les infos de la réduction,
            - On vérifie que la réduction est applicable
            - On calcule le prix à réduire,
            - On retourne le résultat.
        */
        $rules = [
            'idSouscription' => [
                'rules'      => 'required|numeric|is_not_unique[souscriptions.id]',
                'errors'     => [
                    'required' => 'Identifiant de souscription invalide.',
                    'numeric'  => 'Identifiant de souscription invalide.',
                    'is_not_unique' => 'Identifiant de souscription invalide.',
                ],
            ],
            'code'       => [
                'rules'  => 'required|is_not_unique[reductions.code]',
                'errors' => [
                    'required'      => "Code de réduction inconnu.",
                    'is_not_unique' => "Code de réduction inconnu.",
                ],
            ],
            'prix'       => [
                'rules'  => 'required|numeric',
                'errors' => [
                    'required' => "Montant inconne.",
                    'numeric'  => "Montant invalide.",
                ],
            ],
        ];

        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $response = [
                'statut'  => 'no',
                'message' => $errorsData['errors'],
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }

        $input = $this->getRequestInput($this->request);
        $souscription = model("SouscriptionsModel")->find($input['idSouscription']);
        $prixInitial = $input['prix'];
        $code        = $input['code'];
        if (!$souscription->cout == $prixInitial) {
            $response = [
                'statut'  => 'no',
                'message' => "Le prix n'est pas en accord avec la souscription.",
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
        }

        $reduction = model("ReductionsModel")->where("code", $code)->first();

        /* On verifie que la reduction est applicable */
        $dateDiff = strtotime($reduction->expiration_date) - strtotime(date('Y-m-d'));
        if ($dateDiff < 0 || $reduction->usage_max_nombre <= $reduction->utilise_nombre) {
            $message = "Code promo Expiré!";
        }
        $idAssureur = model("AssurancesModel")->where("id", $souscription->assurance_id)->findColumn("assureur_id")[0];
        if ($idAssureur != $reduction->auteur_id) {
            $message = "Code promo non utilisateble pour cette assurance.";
        }

        if (isset($message)) {
            $response = [
                'statut'  => 'no',
                'message' => $message,
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
        }

        $reduction = $this->calculateReduction($prixInitial, $reduction);
        $data = [
            "code"          => $code,
            "prixInitial"   => $prixInitial,
            "prixReduction" => $reduction,
            "prixFinal"     => $prixInitial - $reduction
        ];
        $response = [
            'statut'  => 'ok',
            'message' => "Réduction appliquée.",
            'data'    => $data ?? [],
        ];
        return $this->sendResponse($response);
    }

    /**
     * Détermine le montant à réduire
     */
    private function calculateReduction($prixInitial, $reduction): float
    {
        /*
            lorsque la valeure et le taux son défini tous les deux, le taux est appliqué à la limite de la valeure
        */
        $valeur = $reduction->valeur;
        $taux   = $reduction->taux;
        if ($taux && $valeur) {
            $reductionTaux = ($prixInitial * $taux) / 100;
            $reduction     = $reductionTaux > $valeur ? $valeur : $reductionTaux;
        } elseif (!$taux) {
            $reduction = ($prixInitial * $taux) / 100;
        } else {
            $reduction = $valeur;
        }

        return $reduction;
    }

    /**
     * Initialyse la souscription à une assurance en générant un lien,
     * après avoir initié la transaction.        
     *
     * @return \CodeIgniter\HTTP\ResponseInterface
     * @todo remplacer l'opérateur par le mode, à partir duquel nous pourrons avoir l'opérateur en bd.
     */
    public function newInitiateAssurPayment()
    {
        $rules = [
            'idSouscription' => [
                'rules'        => 'required|numeric|is_not_unique[souscriptions.id]',
                'errors'       => [
                    'required' => 'Souscription non définie.',
                    'numeric'  => 'Identifiant de souscription invalide',
                    'is_not_unique' => 'Identifiant de souscription invalide',
                ],
            ],
            'prix'           => [
                'rules'        => 'required|numeric',
                'errors'       => [
                    'required' => 'Prix non défini.',
                    'numeric'  => 'Prix invalide',
                ],
            ],
            'idAssurance'    => [
                'rules'        => 'required|numeric|is_not_unique[assurances.id]',
                'errors'       => [
                    'required' => 'Assurance non spécifié.',
                    'numeric'  => "Identifiant d'assurance invalide",
                    'is_not_unique' => "Identifiant d'assurance invalide",
                ],
            ],
            'idPayOption'    => [
                'rules'      => 'required|numeric|is_not_unique[paiement_options.id]',
                'errors'     => [
                    'required' => 'Option de paiement non spécifiée.',
                    'numeric'  => "option de paiement invalide",
                    'is_not_unique' => "Option de paiement invalide",
                ],
            ],
            'telephone'      => [
                'rules'      => 'if_exist|numeric',
                'errors'     => ['numeric' => 'Numéro de téléphone invalide.'],
            ],
            'pays'           => [
                'rules'      => 'if_exist|is_not_unique[paiement_pays.code]',
                'errors'     => ['is_not_unique' => 'Pays non pris en charge.'],
            ],
            'returnURL'      => [
                'rules'      => 'required|valid_url',
                'errors'     => [
                    'required'  => "L'URL de retour doit être spécifiée.",
                    'valid_url' => "URL de retour non conforme.",
                ],
            ],
            'avance'         => [
                'rules'        => 'required|numeric',
                'errors'       => [
                    'required' => 'Avance non définie.',
                    'numeric'  => "Valeur de l'avance invalide",
                ],
            ],
            'codeReduction'  => [
                'rules'        => 'if_exist|is_not_unique[reductions.code]',
                'errors'       => [
                    'is_not_unique'  => "Code de réduction inconnu.",
                ],
            ],
            'operateur'  => [
                'rules'  => 'required|is_not_unique[paiement_modes.operateur]',
                'errors' => ['required' => 'Opérateur non défini.', 'is_not_unique' => 'Opérateur invalide'],
            ],
        ];

        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $validationError = $errorsData['code'] == ResponseInterface::HTTP_NOT_ACCEPTABLE;
            $response = [
                'statut'  => 'no',
                'message' => $validationError ? $errorsData['errors'] : "Impossible d'initialiser le paiement.",
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }
        $input = $this->getRequestInput($this->request);

        $prixInitial  = (float)$input['prix'];
        $souscription = model("SouscriptionsModel")->find($input['idSouscription']);
        // $prixInitial = (float)$souscription->cout >= $prixInitial ? (float)$souscription->cout : $prixInitial;
        $prixUnitaire  = model("AssurancesModel")->where('id', $input['idAssurance'])->findColumn("prix")[0];
        $payOption     = model("PaiementOptionsModel")->find($input['idPayOption']);
        $codeReduction = $input['codeReduction'] ?? null;
        $prixReduction = 0;
        if ($codeReduction) {
            $reduction = model("ReductionsModel")->where("code", $input['codeReduction'])->first();
            $prixReduction = $reduction->apply((float)$souscription->cout);
        }
        // 1- Déterminer l'avance en fonction du mode de paiement choisi
        /*
            - recupere option paiement
            - Determine montant premier paiement et vérifie paiement prix fourni suffisant
            - si paiement portefeuillle et portefeuille sufffisant, modifier reductions en bd,
            - si non paiement portefeuille, confier modification reduction à la confirmation de statut.
        */
        $prixToPay = (float)$souscription->cout - $prixReduction;
        $minPay = $payOption->get_initial_amount_from_option($prixToPay);
        /* Eliminons l'étape de calcul de TVA
            $tva          = model("TvasModel")->find(1); 
            $prixTVA      = ($prixToPay * $tva->taux) / 100;
            $prixToPayNet = $prixToPay + $prixTVA;
        */
        $prixToPayNet = $prixToPay;
        $avance       = (float)$input['avance'];
        if ($avance < $minPay) {
            $response = [
                'statut'  => 'no',
                'message' => "Le montant minimal à payer pour cette option de paiement est $minPay.",
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
        }
        // 2- Initialisation de la ligne transaction
        $ligneInfo = new LignetransactionEntity([
            "produit_id"         => $input['idAssurance'],
            "produit_group_name" => 'Assurance',
            "souscription_id"    => $souscription->id,
            "quantite"           => 1,
            "prix_unitaire"      => $prixUnitaire,
            "prix_total"         => (float)$souscription->cout,
            "reduction_code"     => $codeReduction,
            "prix_reduction"     => $prixReduction,
            "prix_total_net"     => $prixToPay,
        ]);
        isset($reduction) ? $ligneInfo->reduction_code = $reduction->code : null;
        model("TransactionsModel")->db->transBegin();
        $ligneInfo->id = model("LignetransactionsModel")->insert($ligneInfo);
        // 3- Configuration de la Transaction
        $transactInfo = [
            "code"            => random_string('alnum', 10),
            "motif"           => "Paiement Souscription $souscription->code",
            "beneficiaire_id" => $souscription->souscripteur->id,
            "pay_option_id"   => $payOption->id,
            "prix_total"      => $prixToPay,
            "tva_taux"        => 0, //$tva->taux,
            "valeur_tva"      => 0, //$prixTVA,
            "net_a_payer"     => $prixToPayNet,
            "avance"          => $avance,
            "reste_a_payer"   => $prixToPayNet - $avance >= 0 ? $prixToPayNet - $avance : 0,
            "etat"            => TransactionEntity::INITIE,
        ];
        // 4- Configuration du paiement,
        $operateurId = model("PaiementModesModel")->where('operateur', $input['operateur'])->findColumn('id')[0];
        $paiementInfo = array(
            'code'      => random_string('alnum', 10),
            'montant'   => $avance,
            'statut'    => PaiementEntity::EN_COURS,
            'mode_id'   => $operateurId,
            'auteur_id' => $this->request->utilisateur->id,
            'statut'    => PaiementEntity::EN_COURS,
        );
        // 5- Appeler MonetBill où payer par portefeuille
        if ($input['operateur'] === 'PORTE_FEUILLE') {
            $portefeuille = model('PortefeuillesModel')->where('utilisateur_id', $this->request->utilisateur->id)->first();
            // Débiter le porte feuille
            try {
                // déduire le montant du portefeuille
                $portefeuille->debit($avance);
            } catch (\Throwable $th) {
                $response = [
                    'statut'  => 'no',
                    'message' => $th->getMessage(),
                ];
                return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
            }
            if ($codeReduction) {
                $usedreductionInfo = [
                    "utilisateur_id" => $this->request->utilisateur->id,
                    "reduction_id"   => $reduction->id,
                    "prix_initial"   => (float)$souscription->cout,
                    "prix_deduit"    => $prixReduction,
                    "prix_final"     => $prixToPay,
                ];
                $reduction->update();
            }
            if ($avance >= $prixToPayNet) {
                $today = date('Y-m-d');
                $duree = model("AssurancesModel")->where('id', $input['idAssurance'])->findColumn('duree')[0];
                model("SouscriptionsModel")->update($souscription->id, [
                    "etat" => SouscriptionsEntity::ACTIF,
                    "dateDebutValidite" => $today,
                    "dateFinValidite"   => date('Y-m-d', strtotime("$today + $duree days")),
                ]);
                $transactInfo['etat']   = TransactionEntity::TERMINE;
                $paiementInfo['statut'] = PaiementEntity::VALIDE;
            } else {
                model("SouscriptionsModel")->update($souscription->idSouscription, ["etat" => SouscriptionsEntity::PENDING]);
                $transactInfo['etat']   = TransactionEntity::EN_COURS;
                $paiementInfo['statut'] = PaiementEntity::VALIDE;
            }
            $message = "Paiement réussi.";
        }

        // Sauvegardes
        $transactInfo['id'] = model("TransactionsModel")->insert($transactInfo);
        model("TransactionLignesModel")->insert(['transaction_id' => $transactInfo['id'], 'ligne_id' => $ligneInfo->id]);
        $paiementInfo['transaction_id'] = $transactInfo['id'];
        $paiementInfo['id'] = model("PaiementsModel")->insert($paiementInfo);
        if (isset($usedreductionInfo)) {
            $usedreductionInfo["transaction_id"] = $transactInfo['id'];
            model("UsedReductionModel")->insert($usedreductionInfo);
        }
        model("TransactionsModel")->db->transCommit();

        if ($input['operateur'] != 'PORTE_FEUILLE') {
            $monetbil_args_model = array(
                'amount'      => 0,
                'phone'       => null,
                'locale'      => 'fr', // Display language fr or en
                'country'     => 'CM',
                'currency'    => 'XAF',
                'operator'    => null,
                'item_ref'    => null,
                'payment_ref' => null,
                'user'        => null,
                'first_name'  => null,
                'last_name'   => null,
                'email'       => null,
                'return_url'  => null,
                'notify_url'  => base_url('paiements/notify'),
                'logo'        => base_url("uploads/images/logoinch.jpeg"),
            );
            $monetbil_args = array(
                'amount'      => $avance,
                'phone'       => $input['telephone'] ?? $this->request->utilisateur->tel1,
                'country'     => $input['pays'] ?? 'CM',
                'phone_lock'  => false,
                'locale'      => 'fr', // Display language fr or en
                'operator'    => $input['operateur'],
                'item_ref'    => $souscription->code,
                'payment_ref' => $paiementInfo['code'],
                'user'        => $this->request->utilisateur->code,
                'return_url'  => $input['returnURL'],
            );

            $data = ['url' => \Monetbil::url($monetbil_args + $monetbil_args_model)];
            $message = "Paiement Initié.";
        }

        $response = [
            'statut'  => 'ok',
            'message' => $message,
            'avance' => $avance,
            'toPay' => $prixToPayNet,
            'data'    => $data ?? [],
        ];
        return $this->sendResponse($response);
    }

    /**
     * Initialyse la souscription à une assurance en générant un lien,
     * après avoir initié la transaction.        
     *
     * @return \CodeIgniter\HTTP\ResponseInterface
     * @todo remplacer l'opérateur par le mode, à partir duquel nous pourrons avoir l'opérateur en bd.
     */
    public function InitiateAssurPayment()
    {
        require_once ROOTPATH . 'Modules\Paiements\ThirdParty\monetbil-php-master\monetbil.php';

        $monetbil_args_model = array(
            'amount'      => 0,
            'phone'       => null,
            'locale'      => 'fr', // Display language fr or en
            'country'     => 'CM',
            'currency'    => 'XAF',
            'operator'    => null,
            'item_ref'    => null,
            'payment_ref' => null,
            'user'        => null,
            'first_name'  => null,
            'last_name'   => null,
            'email'       => null,
            'return_url'  => null,
            'notify_url'  => base_url('paiements/notify'),
            'logo'        => base_url("uploads/images/logoinch.jpeg"),
        );

        // // This example show payment url
        // $data = Monetbil::url($monetbil_args);

        $rules = [
            'idSouscription' => [
                'rules'        => 'required|numeric|is_not_unique[souscriptions.id]',
                'errors'       => [
                    'required' => 'Souscription non définie.',
                    'numeric'  => 'Identifiant de souscription invalide',
                    'is_not_unique' => 'Identifiant de souscription invalide',
                ],
            ],
            'prix'           => [
                'rules'        => 'required|numeric',
                'errors'       => [
                    'required' => 'Prix non défini.',
                    'numeric'  => 'Prix invalide',
                ],
            ],
            'idAssurance'    => [
                'rules'        => 'required|numeric|is_not_unique[assurances.id]',
                'errors'       => [
                    'required' => 'Assurance non spécifié.',
                    'numeric'  => "Identifiant d'assurance invalide",
                    'is_not_unique' => "Identifiant d'assurance invalide",
                ],
            ],
            'idPayOption'    => [
                'rules'      => 'required|numeric|is_not_unique[paiement_options.id]',
                'errors'     => [
                    'required' => 'Option de paiement non spécifiée.',
                    'numeric'  => "option de paiement invalide",
                    'is_not_unique' => "Option de paiement invalide",
                ],
            ],
            'telephone'      => [
                'rules'      => 'if_exist|numeric',
                'errors'     => ['numeric' => 'Numéro de téléphone invalide.'],
            ],
            'pays'           => [
                'rules'      => 'if_exist|is_not_unique[paiement_pays.code]',
                'errors'     => ['is_not_unique' => 'Pays non pris en charge.'],
            ],
            'returnURL'      => [
                'rules'      => 'required|valid_url',
                'errors'     => [
                    'required'  => "L'URL de retour doit être spécifiée.",
                    'valid_url' => "URL de retour non conforme.",
                ],
            ],
            'avance'         => [
                'rules'        => 'required|numeric',
                'errors'       => [
                    'required' => 'Avance non définie.',
                    'numeric'  => "Valeur de l'avance invalide",
                ],
            ],
            'codeReduction'  => [
                'rules'        => 'if_exist|is_not_unique[reductions.code]',
                'errors'       => [
                    'is_not_unique'  => "Code de réduction inconnu.",
                ],
            ],
            // 'operateur'      => [
            //     'rules'        => 'required|in_list[CM_ORANGEMONEY,CM_MTNMOBILEMONEY,CM_EUMM]',
            //     'errors'       => [
            //         'required' => 'Opérateur non défini.',
            //         'in_list'  => 'Opérateur invalide',
            //     ],
            // ],
            'operateur'  => [
                'rules'  => 'required|is_not_unique[paiement_modes.operateur]',
                'errors' => ['required' => 'Opérateur non défini.', 'is_not_unique' => 'Opérateur invalide'],
            ],
        ];

        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $validationError = $errorsData['code'] == ResponseInterface::HTTP_NOT_ACCEPTABLE;
            $response = [
                'statut'  => 'no',
                'message' => $validationError ? $errorsData['errors'] : "Impossible d'initialiser le paiement.",
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }
        $input     = $this->getRequestInput($this->request);

        $prixInitial  = $input['prix'];
        $souscription = model("SouscriptionsModel")->find($input['idSouscription']);
        /*
            1- On initie la ligne de transaction,
            2- On initie la transaction,
            3- On initie le paiement,
            4- On met a jour la souscription,
            5- Déclencher le background Job de gestion des paiement,
            6- On appelle monetBill,
            7- On retourne le résultat,
            x- On initie le paiement,    // Ceci sera plustôt fait après la réponse de l'API de paiement
        */
        $prixUnitaire  = model("AssurancesModel")->where('id', $input['idAssurance'])->findColumn("prix")[0];
        $payOption     = model("PaiementOptionsModel")->find($input['idPayOption']);
        if (isset($input['codeReduction'])) {
            $reduction = model("ReductionsModel")->where("code", $input['codeReduction'])->first();
            $prixReduction = $this->calculateReduction($prixInitial, $reduction);
        } else {
            $prixReduction = 0;
        }
        $prixToPay = $prixInitial - $prixReduction;
        // Eliminons l'étape de calcul de TVA
        $prixToPayNet = $prixToPay;
        $avance       = (float)$input['avance'];
        $minPay       = $payOption->get_initial_amount_from_option($prixToPayNet);
        if ($avance < $minPay) {
            $response = [
                'statut'  => 'no',
                'message' => "Le montant minimal à payer pour cette option de paiement est $minPay.",
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
        }
        // 1- Initiation de la ligne transaction
        $ligneInfo = new LignetransactionEntity([
            "produit_id"         => $input['idAssurance'],
            "produit_group_name" => 'Assurance',
            "souscription_id"    => $souscription->id,
            "quantite"           => 1,
            "prix_unitaire"      => $prixUnitaire,
            "prix_total"         => $prixInitial,
            // "reduction_code"     => $reduction->code,
            "prix_reduction"     => $prixReduction,
            "prix_total_net"     => $prixToPay,
        ]);
        isset($reduction) ? $ligneInfo->reduction_code = $reduction->code : null;
        model("TransactionsModel")->db->transBegin();
        $ligneInfo->id = model("LignetransactionsModel")->insert($ligneInfo);

        // 2- Initiation de la Transaction
        /*$tva          = model("TvasModel")->find(1); 
        $prixTVA      = ($prixToPay * $tva->taux) / 100;
        $prixToPayNet = $prixToPay + $prixTVA;*/

        /** @todo après confirmation du paiement par l'API, mettre à jour l'état en fonction de la valeur
         * du reste_a_payer. conf: document word ReferenceClassDiagramme.
         * @done
         */
        // $transactInfo = new TransactionEntity([
        // print_r($souscription->souscripteur->id);
        // exit;
        $transactInfo = [
            "code"            => random_string('alnum', 10),
            "motif"           => "Paiement Souscription $souscription->code",
            "beneficiaire_id" => $souscription->souscripteur->id, //['idUtilisateur'],
            "pay_option_id"   => $payOption->id,
            "prix_total"      => $prixToPay,
            "tva_taux"        => 0, //$tva->taux,
            "valeur_tva"      => 0, //$prixTVA,
            "net_a_payer"     => $prixToPayNet,
            "avance"          => $avance,
            "reste_a_payer"   => $prixToPayNet - $avance,
            "etat"            => TransactionEntity::INITIE,
            // ]);
        ];
        $transactInfo['id'] = model("TransactionsModel")->insert($transactInfo);
        model("TransactionLignesModel")->insert(['transaction_id' => $transactInfo['id'], 'ligne_id' => $ligneInfo->id]);

        // 3- On initie le paiement,
        $operateurId = model("PaiementModesModel")->where('operateur', $input['operateur'])->findColumn('id')[0];
        $paiementInfo = array(
            'code'      => random_string('alnum', 10),
            'montant'   => $avance,
            'statut'    => PaiementEntity::EN_COURS,
            'mode_id'   => $operateurId,
            'auteur_id' => $this->request->utilisateur->id,
            'transaction_id' => $transactInfo['id'],
        );
        $paiementInfo['id'] = model("PaiementsModel")->insert($paiementInfo);
        model("TransactionsModel")->db->transCommit();
        // 4- Mise à jour de la souscription
        /** Cette étape est déplacée dans la gestion de la réponse de l'API. Pour un mode de paiement non portefeuille */
        // Moi meme 

        // 5- Déclencher le background job de gestion des paiements
        /** Cette étape est déplacée dans la gestion de la réponse de l'API. Pour un mode de paiement non portefeuille */

        // 6- Appeler MonetBill où payer par portefeuille
        // if ($input['operateur'] === 'PORTE_FEUILLE') {
        //     # code...
        // }
        $monetbil_args = array(
            'amount'      => $avance,
            'phone'       => $input['telephone'] ?? $this->request->utilisateur->tel1,
            'country'     => $input['pays'] ?? 'CM',
            'phone_lock'  => false,
            'locale'      => 'fr', // Display language fr or en
            'operator'    => $input['operateur'],
            'item_ref'    => $souscription->code,
            'payment_ref' => $paiementInfo['code'],
            'user'        => $this->request->utilisateur->code,
            'return_url'  => $input['returnURL'],
        );

        // This example show payment url
        $data = ['url' => \Monetbil::url($monetbil_args + $monetbil_args_model)];

        $response = [
            'statut'  => 'ok',
            'message' => "Paiement Initié.",
            'data'    => $data,
        ];
        return $this->sendResponse($response);
    }

    /**
     * Valide un paiement effectué par Monetbil et l'enregistre.
     * 
     * Elle est appellée uniquement par l'API Monetbil
     */
    public function setPayStatus()
    {
        require_once ROOTPATH . 'Modules\Paiements\ThirdParty\monetbil-php-master\monetbil.php';

        $params = Monetbil::getPost();
        $service_secret = Monetbil::getServiceSecret();

        if (!Monetbil::checkSign($service_secret, $params)) {
            header('HTTP/1.0 403 Forbidden');
            exit('Error: Invalid signature');
        }

        $service          = Monetbil::getPost('service');
        $transaction_id   = Monetbil::getPost('transaction_id');
        $transaction_uuid = Monetbil::getPost('transaction_uuid');
        $phone            = Monetbil::getPost('msisdn');
        $amount           = Monetbil::getPost('amount');
        $fee              = Monetbil::getPost('fee');
        $status           = Monetbil::getPost('status');
        $message          = Monetbil::getPost('message');
        $country_name     = Monetbil::getPost('country_name');
        $country_iso      = Monetbil::getPost('country_iso');
        $country_code     = Monetbil::getPost('country_code');
        $mccmnc           = Monetbil::getPost('mccmnc');
        $operator         = Monetbil::getPost('mobile_operator_name');
        $currency         = Monetbil::getPost('currency');
        $user             = Monetbil::getPost('user');
        $item_ref         = Monetbil::getPost('item_ref');
        $payment_ref      = Monetbil::getPost('payment_ref');
        $first_name       = Monetbil::getPost('first_name');
        $last_name        = Monetbil::getPost('last_name');
        $email            = Monetbil::getPost('email');

        list($payment_status) = Monetbil::checkPayment($transaction_id);

        $souscription = model("SouscriptionsModel")->where("code", $item_ref)->first();
        $ligneTransact = model("LigneTransactionModel")->where('souscription_id', $souscription->id)->first();
        $idLigneTransact = $ligneTransact->id;
        $idAssurance = $ligneTransact->idproduit_id;
        unset($ligneTransact);
        $transactInfo = model("TransactionsModel")->join("transaction_lignes", "transaction_id=transactions.id")
            ->select('transactions.*')
            ->where("ligne_id", $idLigneTransact)
            ->first();

        if (\Monetbil::STATUS_SUCCESS == $payment_status) {
            // Successful payment!
            model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::VALIDE)->update();

            if ($transactInfo['reste_a_payer'] <= 0) {
                model("TransactionsModel")->update($transactInfo['id'], ['etat' => TransactionEntity::TERMINE]);
            } else {
                model("TransactionsModel")->update($transactInfo['id'], ['etat' => TransactionEntity::EN_COURS]);
            }
            $souscription = model("SouscriptionsModel")->where("code", $item_ref)->first();
            $duree = model("AssurancesModel")->where('id', $idAssurance)->findColumn('duree')[0];
            $today = date('Y-m-d');

            model("SouscriptionsModel")->where("code", $item_ref)->set([
                "etat"              => SouscriptionsEntity::ACTIF,
                "dateDebutValidite" => $today,
                "dateFinValidite"   => date('Y-m-d', strtotime("$today + $duree days")),
            ])->update();
            // Mark the order as paid in your system
        } elseif (\Monetbil::STATUS_CANCELLED == $payment_status) {
            // Transaction cancelled
            model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::ANNULE)->update();
        } else {
            // Payment failed!
            model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::ECHOUE)->update();
        }

        /** @todo Line to remove */
        file_put_contents(WRITEPATH . PATH_SEPARATOR . 'BillContent' . PATH_SEPARATOR . date('Y-m-d') . '.txt', json_encode([
            'received data' => Monetbil::getPost()
        ]));
        // Received
        exit('received');
    }
    /** @todo
     * Une fonction payForTransact doit etre crée afin de procéder au paiement directement
     * depuis la liste des transactions. Elle se chargera de vérifier à partir de la transaction
     * de quelle opération nous avons besoin et la fera exécuter
     */
    public function payForAvis()
    {
        /* Effectue le paiement pour la demande d'avis expert initiée par le médecin. */

        $rules = [
            'operateur'  => [
                'rules'  => 'required|is_not_unique[paiement_modes.operateur]',
                'errors' => ['required' => 'Opérateur non défini.', 'is_not_unique' => 'Opérateur invalide'],
            ],
            'idAvis' => [
                'rules'  => 'required|is_not_unique[avisexpert.id]',
                'errors' => ['required' => 'Identification d\'avis incorrect.', 'is_not_unique' => 'Identification d\'avis incorrect.'],
            ],
        ];
        $pay_rules = [
            'operateur'  => [
                'rules'  => 'required|is_not_unique[paiement_modes.operateur]',
                'errors' => ['required' => 'Opérateur non défini.', 'is_not_unique' => 'Opérateur invalide'],
            ],
            'telephone'  => [
                'rules'  => 'required|numeric',
                'errors' => ['required' => 'Numéro de téléphone requis pour ce mode de paiement.', 'numeric' => 'Numéro de téléphone invalide.']
            ],
            'returnURL'  => [
                'rules'  => 'required|valid_url',
                'errors' => ['required' => 'L\'URL de retour doit être spécifiée pour ce mode de paiement.', 'valid_url' => 'URL de retour non conforme.']
            ],
            // 'pays'       => [
            //     'rules'  => 'if_exist|is_not_unique[paiement_pays.code]',
            //     'errors' => ['is_not_unique' => 'Pays non pris en charge.'],
            // ],
            'idAvis' => [
                'rules'  => 'required|is_not_unique[avisexpert.id]',
                'errors' => ['required' => 'Identification d\'avis incorrect.', 'is_not_unique' => 'Identification d\'avis incorrect.'],
            ],
        ];

        $input = $this->getRequestInput($this->request);
        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
            if ($input['operateur'] != 'PORTE_FEUILLE' && !$this->validate($pay_rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $validationError = $errorsData['code'] == ResponseInterface::HTTP_NOT_ACCEPTABLE;
            $response = [
                'statut'  => 'no',
                'message' => $validationError ? $errorsData['errors'] : "Impossible d'envoyer cette demande.",
                'errors'  => $errorsData['errors'],
                'data'  => $input,
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }


        // Récupérer les éléments de transaction
        $avis = model('AvisExpertModel')->find($input['idAvis']);
        $transaction = model('TransactionsModel')
            ->join('transaction_lignes', 'transactions.id=transaction_id', 'left')
            ->join('lignetransactions',  'ligne_id=lignetransactions.id', 'left')
            ->where('produit_group_name', 'Avis Expert')
            ->where('produit_id', $avis->id)
            ->first();
        // print_r($transaction);
        // exit;
        $amount = (float)$transaction->prix_total_net;
        // Déterminer le moyen de paiement
        $paiementInfo = [
            'code'      => random_string('alnum', 10),
            'montant'   => $amount,
            'statut'    => PaiementEntity::EN_COURS, // ::VALIDE,
            'mode_id'   => model("PaiementModesModel")->where('operateur', $input['operateur'])->findColumn('id')[0] ?? 1,
            'auteur_id' => $this->request->utilisateur->id,
            'transaction_id' => $transaction->transaction_id,
        ];
        model("PaiementsModel")->db->transBegin();
        if ($input['operateur'] == 'PORTE_FEUILLE') {
            $portefeuille = model('PortefeuillesModel')->where('utilisateur_id', $this->request->utilisateur->id)->first();
            // Débiter le porte feuille
            try {
                // déduire le montant du portefeuille
                $portefeuille->debit($amount);
            } catch (\Throwable $th) {
                $response = [
                    'statut'  => 'no',
                    'message' => $th->getMessage(),
                ];
                return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
            }

            // Modifier les status
            // model('TransactionsModel')->update($transaction->transaction_id, ['etat' => TransactionEntity::TERMINE]);
            model("TransactionsModel")->update($transaction->transaction_id, [
                "avance" => (float)$transaction->avance + $amount,
                "reste_a_payer" => (float)$transaction->reste_a_payer - $amount,
                'etat' => TransactionEntity::TERMINE,
            ]);
            model('AvisExpertModel')->update($avis->id, ['statut' => AvisExpertEntity::EN_COURS]);
            $paiementInfo['statut'] = PaiementEntity::VALIDE;
            $message = "Paiement réussi.";
        } else {
            // Initialiser la transaction Monetbill
            $monetbil_args = array(
                'amount'      => $amount,
                'phone'       => $input['telephone'] ?? $this->request->utilisateur->tel1,
                'country'     => $input['pays'] ?? 'CM',
                'phone_lock'  => false,
                'locale'      => 'fr', // Display language fr or en
                'operator'    => $input['operateur'],
                'item_ref'    => $avis->id,
                'payment_ref' => $paiementInfo['code'],
                'user'        => $this->request->utilisateur->code,
                'return_url'  => $input['returnURL'],
                'notify_url'  => base_url('paiements/notfyAvis'),
                'logo'        => base_url("uploads/images/logoinch.jpeg"),
            );
            // This example show payment url
            $data    = ['url' => \Monetbil::url($monetbil_args)];
            $message = "Paiement Initié.";
            $paiementInfo['statut'] = PaiementEntity::EN_COURS;
        }
        // Ajouter le paiement
        model("PaiementsModel")->insert($paiementInfo);
        model("PaiementsModel")->db->transCommit();
        $response = [
            'statut'  => 'ok',
            'message' => $message,
            'data'    => $data ?? [],
        ];
        return $this->sendResponse($response);
    }
    public function payForConsult()
    {
        /* Complète le paiement pour une consultation. */
        // Cette différence est nécessaire parceque en cas de paiement par portefeuille
        // les conditions de $rules sont nécessaires et suffisantes
        $rules = [
            'operateur'  => [
                'rules'  => 'required|is_not_unique[paiement_modes.operateur]',
                'errors' => ['required' => 'Opérateur non défini.', 'is_not_unique' => 'Opérateur invalide'],
            ],
            'idConsultation ' => [
                'rules'  => 'required|is_not_unique[consultations.id]',
                'errors' => ['required' => 'Identification de consultation incorrect.', 'is_not_unique' => 'Identification de consultation incorrect.'],
            ],
        ];
        $pay_rules = [
            'operateur'  => [
                'rules'  => 'required|is_not_unique[paiement_modes.operateur]',
                'errors' => ['required' => 'Opérateur non défini.', 'is_not_unique' => 'Opérateur invalide'],
            ],
            'montant'  => [
                'rules'  => 'required|numeric',
                'errors' => ['required' => 'Montant non défini.', 'numeric' => 'Montant invalide'],
            ],
            'telephone'  => [
                'rules'  => 'required|numeric',
                'errors' => ['required' => 'Numéro de téléphone requis pour ce mode de paiement.', 'numeric' => 'Numéro de téléphone invalide.']
            ],
            'returnURL'  => [
                'rules'  => 'required|valid_url',
                'errors' => ['required' => 'L\'URL de retour doit être spécifiée pour ce mode de paiement.', 'valid_url' => 'URL de retour non conforme.']
            ],
            'idConsultation ' => [
                'rules'  => 'required|is_not_unique[consultations.id]',
                'errors' => ['required' => 'Identification de consultation incorrect.', 'is_not_unique' => 'Identification de consultation incorrect.'],
            ],
        ];

        $input = $this->getRequestInput($this->request);
        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
            if ($input['operateur'] != 'PORTE_FEUILLE' && !$this->validate($pay_rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $validationError = $errorsData['code'] == ResponseInterface::HTTP_NOT_ACCEPTABLE;
            $response = [
                'statut'  => 'no',
                'message' => $validationError ? $errorsData['errors'] : "Impossible d'envoyer cette demande.",
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }


        // Récupérer les éléments de transaction
        $consult = model('ConsultationsModel')->find($input['idConsultation']);
        $transaction = model('TransactionsModel')
            ->join('transaction_lignes', 'transactions.id=transaction_id', 'left')
            ->join('lignetransactions',  'ligne_id=lignetransactions.id', 'left')
            ->where('produit_group_name', 'Consultation')
            ->where('produit_id', $consult->id)
            ->first();

        $amount = (float)$transaction->reste_a_payer;
        if ($amount != $input['montant']) {
            $response = [
                'statut'  => 'no',
                'message' => "Le montant de l'opération est incorrect.",
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
        }
        // Déterminer le moyen de paiement
        $paiementInfo = [
            'code'      => random_string('alnum', 10),
            'montant'   => $amount,
            'statut'    => PaiementEntity::EN_COURS, // ::VALIDE,
            'mode_id'   => (int)model("PaiementModesModel")->where('operateur', $input['operateur'])->findColumn('id')[0] ?? 1,
            'auteur_id' => $this->request->utilisateur->id,
            'transaction_id' => (int)$transaction->transaction_id,
        ];
        model("PaiementsModel")->db->transBegin();
        if ($input['operateur'] == 'PORTE_FEUILLE') {
            $portefeuille = model('PortefeuillesModel')->where('utilisateur_id', $this->request->utilisateur->id)->first();
            // Débiter le porte feuille
            try {
                // déduire le montant du portefeuille
                $portefeuille->debit($amount);
            } catch (\Throwable $th) {
                $response = [
                    'statut'  => 'no',
                    'message' => $th->getMessage(),
                ];
                return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
            }

            // mettre à jour l'agenda du médecin
            $heure = $consult->heure;
            $agenda = model("AgendasModel")->where('proprietaire_id', $consult->medecin_user_id['idUtilisateur'])
                ->where('jour_dispo', $consult->date)
                ->where('heure_dispo_debut <=', $heure)
                ->where('heure_dispo_fin >=', $heure)
                ->first();
            $slot = array_filter($agenda->slots, function ($sl) use ($heure) {
                return strtotime($sl['debut']) <= strtotime($heure) && strtotime($sl['fin']) > strtotime($heure);
            });
            $slot = reset($slot);
            $agenda->removeSlot($slot['id']);
            model("AgendasModel")->update($agenda->id, ['slots' => $agenda->slots]);

            // Modifier les status
            // model('TransactionsModel')->update($transaction->transaction_id, ['etat' => TransactionEntity::TERMINE]);
            model("TransactionsModel")->update($transaction->transaction_id, [
                "avance" => (float)$transaction->avance + $amount,
                "reste_a_payer" => (float)$transaction->reste_a_payer - $amount,
                'etat' => TransactionEntity::TERMINE,
            ]);
            model('ConsultationsModel')->update($consult->id, ['statut' => ConsultationEntity::VALIDE]);
            $paiementInfo['statut'] = PaiementEntity::VALIDE;
            $message = "Paiement réussi.";
        } else {
            // Initialiser la transaction Monetbill
            $monetbil_args = array(
                'amount'      => $amount,
                'phone'       => $input['telephone'] ?? $this->request->utilisateur->tel1,
                'country'     => $input['pays'] ?? 'CM',
                'phone_lock'  => false,
                'locale'      => 'fr', // Display language fr or en
                'operator'    => $input['operateur'],
                'item_ref'    => $consult->id,
                'payment_ref' => $paiementInfo['code'],
                'user'        => $this->request->utilisateur->code,
                'return_url'  => $input['returnURL'],
                'notify_url'  => base_url('paiements/notfyConsult'),
                'logo'        => base_url("uploads/images/logoinch.jpeg"),
            );
            // This example show payment url
            $data    = ['url' => \Monetbil::url($monetbil_args)];
            $message = "Paiement Initié.";
        }
        // Ajouter le paiement car le statut peut passer à annulé
        $paiementInfo['id'] = model("PaiementsModel")->insert($paiementInfo);
        model("PaiementsModel")->db->transCommit();
        $response = [
            'statut'  => 'ok',
            'message' => $message,
            'data'    => $data ?? [],
        ];
        return $this->sendResponse($response);
    }

    /** @todo pourrait etre fait sans */
    public function setConsultPayStatus()
    {
        /* To be implemented*/
    }

    /** @todo pourrait etre fait sans */
    public function setAvisPayStatus()
    {
        /* To be implemented*/
    }

    /** @todo pourrait etre fait sans */
    public function setRechargePayStatus()
    {
        /* To be implemented*/
    }

    public function localSetConsultPayStatus()
    {
        $rules = [
            'transaction_id' => 'required',
            'item_ref'       => 'required',
            'payment_ref'    => 'required',
            'payment_status' => 'required',
        ];

        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $response = [
                'statut'  => 'no',
                'message' => $errorsData['errors'],
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }
        $input = $this->getRequestInput($this->request);
        $item_ref       = $input['item_ref'];
        $payment_ref    = $input['payment_ref'];
        $payment_status = $input['payment_status'];
        $api_transact_id = $input['transaction_id'];
        /*
            mettre à jour le statut du paiement
            mettre à jour l'agenda du médecin (en cas de validation)
            mettre à jour le statut de la consultation
            mettre à jour le statut de la transaction
        */
        // Mettre à jour le statut de la transaction
        // $ligneTransact = model("LignetransactionsModel")->where('produit_id', $consultation->id)->where('produit_group_name', 'Consultation')->first();
        // $idLigneTransact = $ligneTransact->id;
        // unset($ligneTransact);
        // $transactInfo = model("TransactionsModel")->join("transaction_lignes", "transaction_id=transactions.id")
        //     ->select('transactions.*')
        //     ->where("ligne_id", $idLigneTransact)
        //     ->first();
        $identifier = $this->getIdentifier($item_ref, 'id');
        $consultID = model("ConsultationsModel")->where($identifier['name'], $identifier['value'])->findColumn('id')[0];

        $transactInfo = model('TransactionsModel')
            ->join('transaction_lignes', 'transactions.id=transaction_id', 'left')
            ->join('lignetransactions',  'ligne_id=lignetransactions.id', 'left')
            ->where('produit_group_name', 'Consultation')
            ->where('produit_id', $consultID)
            ->first();
        if (!$transactInfo) {
            $response = [
                'statut'  => 'no',
                'message' => 'Transaction introuvable',
                'data'    => [],
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
        }
        model("PaiementsModel")->db->transBegin();
        if (Monetbil::STATUS_SUCCESS == $payment_status) {
            // Successful payment!
            // mettre à jour le statut du paiement
            $payedAmount = model("PaiementsModel")->where("code", $payment_ref)->findColumn("montant")[0];
            model("PaiementsModel")->where("code", $payment_ref)->set('code', $api_transact_id)->set('statut', PaiementEntity::VALIDE)->update();
            $message = "Paiement Réussi.";
            $code = ResponseInterface::HTTP_OK;
            // mettre à jour le statut de la transaction
            if ($transactInfo->reste_a_payer <= 0) {
                model("TransactionsModel")->update($transactInfo->id, [
                    "avance" => (float)$transactInfo->avance + (float)$payedAmount,
                    "reste_a_payer" => (float)$transactInfo->reste_a_payer - (float)$payedAmount,
                    'etat' => TransactionEntity::TERMINE,
                ]);
                // mettre à jour le statut de la consultation
                $consultation = model("ConsultationsModel")->where("code", $item_ref)->first();
                model("ConsultationsModel")->where("id", $consultation->id)->set('statut', ConsultationEntity::VALIDE)->update();

                // mettre à jour l'agenda du médecin
                $heure = $consultation->heure;
                $agenda = model("AgendasModel")->where('proprietaire_id', $consultation->medecin_user_id['idUtilisateur'])
                    ->where('jour_dispo', $consultation->date)
                    ->where('heure_dispo_debut <=', $heure)
                    ->where('heure_dispo_fin >=', $heure)
                    ->first();

                $slot = array_filter($agenda->slots, function ($sl) use ($heure) {
                    return strtotime($sl['debut']) <= strtotime($heure) && strtotime($sl['fin']) > strtotime($heure);
                });
                $slot = reset($slot);
                $agenda->removeSlot($slot['id']);
                model("AgendasModel")->update($agenda->id, ['slots' => $agenda->slots]);

                $data = ['idConsultation' => $consultation->id];
            } else {
                model("TransactionsModel")->update($transactInfo->id, ['etat' => TransactionEntity::EN_COURS]);
            }
        } elseif (Monetbil::STATUS_CANCELLED == $payment_status) {
            // mettre à jour le statut du paiement
            // model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::ANNULE)->update();
            model("PaiementsModel")->where("code", $payment_ref)->set('code', $api_transact_id)->set('statut', PaiementEntity::ANNULE)->update();

            // mettre à jour le statut de la transaction
            // model("TransactionsModel")->update($transactInfo->id, ['etat' => TransactionEntity::TERMINE]);
            // mettre à jour le statut de la consultation
            // model("ConsultationsModel")->where("code", $item_ref)->set('statut', ConsultationEntity::ANNULE)->update();
            $message = "Paiement Annulé.";
            $code = ResponseInterface::HTTP_BAD_REQUEST;
        } else {
            // Payment failed!
            // mettre à jour le statut du paiement
            // model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::ECHOUE)->update();
            model("PaiementsModel")->where("code", $payment_ref)->set('code', $api_transact_id)->set('statut', PaiementEntity::ECHOUE)->update();

            // mettre à jour le statut de la transaction
            // model("TransactionsModel")->update($transactInfo->id, ['etat' => TransactionEntity::TERMINE]);
            // mettre à jour le statut de la consultation
            // model("ConsultationsModel")->where("code", $item_ref)->set('statut', ConsultationEntity::ECHOUE)->update();
            $message = "Echec du Paiement.";
            $code = ResponseInterface::HTTP_BAD_REQUEST;
        }
        model("PaiementsModel")->db->transCommit();
        $response = [
            'statut'  => 'ok',
            'message' => $message,
            'data'    => $data ?? [],
        ];
        return $this->sendResponse($response, $code);
    }

    public function localSetAvisPayStatus()
    {
        $rules = [
            'transaction_id' => 'required',
            'item_ref'       => 'required',
            'payment_ref'    => 'required',
            'payment_status' => 'required',
        ];

        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $response = [
                'statut'  => 'no',
                'message' => $errorsData['errors'],
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }
        $input = $this->getRequestInput($this->request);
        $item_ref       = $input['item_ref'];
        $payment_ref    = $input['payment_ref'];
        $payment_status = $input['payment_status'];
        $api_transact_id = $input['transaction_id'];
        /*
            mettre à jour le statut du paiement
            mettre à jour l'agenda du médecin (en cas de validation)
            mettre à jour le statut de la consultation
            mettre à jour le statut de la transaction
        */
        // Mettre à jour le statut de la transaction
        $transaction = model('TransactionsModel')
            ->join('transaction_lignes', 'transactions.id=transaction_id', 'left')
            ->join('lignetransactions',  'ligne_id=lignetransactions.id', 'left')
            ->where('produit_group_name', 'Avis Expert')
            ->where('produit_id', $item_ref)
            ->first();
        if (!$transaction) {
            $response = [
                'statut'  => 'no',
                'message' => 'Transaction introuvable',
                'data'    => [],
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
        }

        if (Monetbil::STATUS_SUCCESS == $payment_status) {
            // Successful payment!
            // mettre à jour le statut du paiement
            $payedAmount = model("PaiementsModel")->where("code", $payment_ref)->findColumn("montant")[0];
            model("PaiementsModel")->where("code", $payment_ref)->set('code', $api_transact_id)->set('statut', PaiementEntity::VALIDE)->update();

            $message = "Paiement Réussi.";
            $code = ResponseInterface::HTTP_OK;
            // mettre à jour le statut de la transaction
            // model("TransactionsModel")->update($transaction->transaction_id, ['etat' => TransactionEntity::TERMINE]);
            model("TransactionsModel")->update($transaction->transaction_id, [
                "avance" => (float)$transaction->avance + (float)$payedAmount,
                "reste_a_payer" => (float)$transaction->reste_a_payer - (float)$payedAmount,
                'etat' => TransactionEntity::TERMINE,
            ]);
            // mettre à jour le statut de l'avis
            model('AvisExpertModel')->update($item_ref, ['statut' => AvisExpertEntity::EN_COURS]);
            // mettre à jour la souscription (les services disponibles)
        } elseif (Monetbil::STATUS_CANCELLED == $payment_status) {
            // mettre à jour le statut du paiement
            model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::ANNULE)->update();
            // mettre à jour le statut de la transaction
            // model("TransactionsModel")->update($transaction->transaction_id, ['etat' => TransactionEntity::TERMINE]);
            // mettre à jour le statut de l'avis
            // model('AvisExpertModel')->update($item_ref, ['statut' => AvisExpertEntity::ANNULE]);
            $message = "Paiement Annulé.";
            $code = ResponseInterface::HTTP_BAD_REQUEST;
        } else {
            // Payment failed!
            // mettre à jour le statut du paiement
            model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::ECHOUE)->update();
            $message = "Echec du Paiement.";
            $code = ResponseInterface::HTTP_BAD_REQUEST;
        }
        $response = [
            'statut'  => 'ok',
            'message' => $message,
            'data'    => [],
        ];
        return $this->sendResponse($response, $code);
    }

    public function localSetRechargePayStatus()
    {
        $rules = [
            'transaction_id' => 'required',
            'item_ref'       => 'required',
            'payment_ref'    => 'required',
            'payment_status' => 'required',
        ];

        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $response = [
                'statut'  => 'no',
                'message' => $errorsData['errors'],
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }
        $input = $this->getRequestInput($this->request);
        $item_ref       = $input['item_ref'];
        $payment_ref    = $input['payment_ref'];
        $payment_status = $input['payment_status'];
        $api_transact_id = $input['transaction_id'];

        // Mettre à jour le statut de la transaction
        $transaction = model('TransactionsModel')->where('id', $item_ref)->first();
        if (!$transaction) {
            $response = [
                'statut'  => 'no',
                'message' => 'Transaction introuvable',
                'data'    => [],
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
        }

        if (Monetbil::STATUS_SUCCESS == $payment_status) {
            // Successful payment!
            // mettre à jour le statut du paiement
            // model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::VALIDE)->update();
            model("PaiementsModel")->where("code", $payment_ref)->set('code', $api_transact_id)->set('statut', PaiementEntity::VALIDE)->update();
            $message = "Paiement Réussi.";
            $code = ResponseInterface::HTTP_OK;
            // mettre à jour le statut de la transaction
            model("TransactionsModel")->update($transaction->id, ['etat' => TransactionEntity::TERMINE]);
        } elseif (Monetbil::STATUS_CANCELLED == $payment_status) {
            // mettre à jour le statut du paiement
            model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::ANNULE)->update();
            // mettre à jour le statut de la transaction
            model("TransactionsModel")->update($transaction->id, ['etat' => TransactionEntity::EN_COURS]);
            $message = "Paiement Annulé.";
            $code = ResponseInterface::HTTP_BAD_REQUEST;
        } else {
            // Payment failed!
            // mettre à jour le statut du paiement
            model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::ECHOUE)->update();
            // mettre à jour le statut de la transaction
            model("TransactionsModel")->update($transaction->id, ['etat' => TransactionEntity::EN_COURS]);
            $message = "Echec du Paiement.";
            $code = ResponseInterface::HTTP_BAD_REQUEST;
        }
        $response = [
            'statut'  => 'ok',
            'message' => $message,
            'data'    => [],
        ];
        return $this->sendResponse($response, $code);
    }

    /**
     * localSetPayStatus fait approximativement les mêmes traitements que setPayStatus
     * à la différence que les données transites par le front au lieu de venir directement
     * de monetbill. Ceci est utili pour les tests en local.
     *
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function newLocalSetPayStatus()
    {
        $rules = [
            'transaction_id' => 'required',
            'item_ref'       => 'required',
            'payment_ref'    => 'required',
            'payment_status' => 'required',
        ];

        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $response = [
                'statut'  => 'no',
                'message' => $errorsData['errors'],
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }
        $input = $this->getRequestInput($this->request);

        $MB_transact_id = $input['transaction_id'];
        $item_ref       = $input['item_ref'];
        $payment_ref    = $input['payment_ref'];
        $payment_status = $input['payment_status'];

        // Récupérer les éléments de transaction
        $souscript   = model("SouscriptionsModel")->where("code", $item_ref)->first();
        $transaction = model('TransactionsModel')
            ->join('transaction_lignes', 'transactions.id=transaction_id', 'left')
            ->join('lignetransactions',  'ligne_id=lignetransactions.id', 'left')
            ->select('*, lignetransactions.prix_total as prixTotal')
            ->where('produit_group_name', 'Assurance')
            ->where('produit_id', $souscript->id)
            ->first();

        model("PaiementsModel")->db->transBegin();
        if (Monetbil::STATUS_SUCCESS == $payment_status) {
            model("PaiementsModel")->where("code", $payment_ref)->set('code', $MB_transact_id)->set('statut', PaiementEntity::VALIDE)->update();
            if ($transaction->reduction_code) {
                $reduction = model("ReductionsModel")->join("usedreductions", "reductions.id=reduction_id", "left")
                    ->where("code", $transaction->reduction_code)
                    ->first();
                if ($reduction && ($transaction->id != $reduction->transaction_id)) {
                    model("UsedReductionModel")->insert([
                        "utilisateur_id" => $this->request->utilisateur->id,
                        "reduction_id"   => $reduction->id,
                        "transaction_id" => $transaction->id,
                        "prix_initial"   => $transaction->prixTotal,
                        "prix_deduit"    => $transaction->prix_reduction,
                        "prix_final"     => $transaction->prix_total_net,
                    ]);
                    $reduction->update();
                }
            }
            if ($transaction->reste_a_payer <= 0) {
                model("TransactionsModel")->update($transaction->id, ['etat' => TransactionEntity::TERMINE]);
                $today = date('Y-m-d');
                $duree = model("AssurancesModel")->where('id', $souscript->assurance_id)->findColumn('duree')[0];
                model("SouscriptionsModel")->update($souscript->id, [
                    "etat" => SouscriptionsEntity::ACTIF,
                    "dateDebutValidite" => $today,
                    "dateFinValidite"   => date('Y-m-d', strtotime("$today + $duree days")),
                ]);
            } else {
                model("TransactionsModel")->update($transaction->id, ['etat' => TransactionEntity::EN_COURS]);
            }
            $message = "Paiement Réussi.";
            $code = ResponseInterface::HTTP_OK;
        } elseif (Monetbil::STATUS_CANCELLED == $payment_status) {
            model("PaiementsModel")->where("code", $payment_ref)->set('code', $MB_transact_id)->set('statut', PaiementEntity::ANNULE)->update();
            $message = "Paiement Annulé.";
            $code = ResponseInterface::HTTP_BAD_REQUEST;
        } else {
            model("PaiementsModel")->where("code", $payment_ref)->set('code', $MB_transact_id)->set('statut', PaiementEntity::ECHOUE)->update();
            $message = "Echec du Paiement.";
            $code = ResponseInterface::HTTP_BAD_REQUEST;
        }
        model("PaiementsModel")->db->transCommit();

        $response = [
            'statut'  => 'ok',
            'message' => $message,
        ];
        return $this->sendResponse($response, $code);
    }

    /** @deprecated
     * localSetPayStatus fait approximativement les mêmes traitements que setPayStatus
     * à la différence que les données transites par le front au lieu de venir directement
     * de monetbill. Ceci est utili pour les tests en local.
     *
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function localSetPayStatus()
    {
        $rules = [
            'transaction_id' => 'required',
            'item_ref'       => 'required',
            'payment_ref'    => 'required',
            'payment_status' => 'required',
        ];

        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $response = [
                'statut'  => 'no',
                'message' => $errorsData['errors'],
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }
        $input = $this->getRequestInput($this->request);
        $MB_transaction_id = $input['transaction_id'];
        $item_ref          = $input['item_ref'];
        $payment_ref       = $input['payment_ref'];
        $payment_status    = $input['payment_status'];

        $souscription = model("SouscriptionsModel")->where("code", $item_ref)->first();
        $ligneTransact = model("LignetransactionsModel")->where('souscription_id', $souscription->id)->first();
        $idLigneTransact = $ligneTransact->id; // ?? null;
        $idAssurance = $ligneTransact->produit_id; // ?? null;
        unset($ligneTransact);
        $transactInfo = model("TransactionsModel")->join("transaction_lignes", "transaction_id=transactions.id")
            ->select('transactions.*')
            ->where("ligne_id", $idLigneTransact)
            ->first();
        if (!$transactInfo) {
            $response = [
                'statut'  => 'no',
                'message' => 'Transaction introuvable',
                'data'    => [],
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
        }
        if (Monetbil::STATUS_SUCCESS == $payment_status) {
            // Successful payment!
            model("PaiementsModel")->where("code", $payment_ref)->set('code', $transaction_id)->set('statut', PaiementEntity::VALIDE)->update();
            $message = "Paiement Réussi.";
            $code = ResponseInterface::HTTP_OK;
            if ($transactInfo->reste_a_payer <= 0) {
                model("TransactionsModel")->update($transactInfo->id, ['etat' => TransactionEntity::TERMINE]);
            } else {
                model("TransactionsModel")->update($transactInfo->id, ['etat' => TransactionEntity::EN_COURS]);
            }
            $souscription = model("SouscriptionsModel")->where("code", $item_ref)->first();
            $duree = model("AssurancesModel")->where('id', $idAssurance)->findColumn('duree')[0];
            $today = date('Y-m-d');

            model("SouscriptionsModel")->where("code", $item_ref)->set([
                "etat"              => SouscriptionsEntity::ACTIF,
                "dateDebutValidite" => $today,
                "dateFinValidite"   => date('Y-m-d', strtotime("$today + $duree days")),
            ])->update();

            //associate the subscription services.
            $serviceIds = model("AssuranceServicesModel")->where("assurance_id", $idAssurance)->findColumn('service_id');
            if ($serviceIds) {
                $sousID = $souscription->id;
                $sousServInfo = array_map(function ($serviceId) use ($sousID) {
                    return ['souscription_id' => $sousID, 'service_id' => $serviceId];
                }, $serviceIds);
                model("SouscriptionServicesModel")->insertBatch($sousServInfo);
            }
            // Mark the order as paid in your system
        } elseif (Monetbil::STATUS_CANCELLED == $payment_status) {
            // Transaction cancelled
            model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::ANNULE)->update();
            $message = "Paiement Annulé.";
            $code = ResponseInterface::HTTP_BAD_REQUEST;
        } else {
            // Payment failed!
            model("PaiementsModel")->where("code", $payment_ref)->set('statut', PaiementEntity::ECHOUE)->update();
            $message = "Echec du Paiement.";
            $code = ResponseInterface::HTTP_BAD_REQUEST;
        }

        /** @todo Line to remove */
        file_put_contents(WRITEPATH . '/BillContent/' . date('Y-m-d') . '.txt', json_encode([
            'received data' => Monetbil::getPost()
        ]));
        // Received
        $response = [
            'statut'  => 'ok',
            'message' => $message,
            'data'    => [],
        ];
        return $this->sendResponse($response, $code);
    }

    public function getCountries()
    {
        $response = [
            'statut'  => 'ok',
            'message' => "Pays acceptés pour le paiement.",
            'data'    => model("PaiementPaysModel")->findAll(),
        ];
        return $this->sendResponse($response);
    }

    public function getAllmodePaiement()
    {
        $query = model("PaiementModesModel")->asArray()->select('nom, operateur');
        $data = $query->findall();

        if (!count($data)) { // this case is for a not value in database
            $response = [
                'statut'  => 'no',
                'message' => 'Aucun Mode de paiement trouvé.',
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_ACCEPTED);
        } else {
            $response = [
                'statut'  => 'ok',
                'message' => count($data) . ' Mode(s) de paiement trouvé(s)',
                'data'    => $data,
            ];
            return $this->sendResponse($response);
        }
    }

    /**
     * Effectue un paiement pour une transaction en cours
     * ceci n'est fait que pour une transaction à laquelle au moins un paiement a déjà été fait
     *
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function payForTransact()
    {
        $rules = [
            'idtransaction' => [
                'rules'        => 'required|numeric|is_not_unique[transactions.id]',
                'errors'       => [
                    'required' => 'Transaction non définie.',
                    'numeric'  => 'Identifiant de transaction invalide',
                    'is_not_unique' => 'Identifiant de transaction invalide',
                ],
            ],
            'avance'         => [
                'rules'        => 'required|numeric',
                'errors'       => [
                    'required' => 'Avance non définie.',
                    'numeric'  => "Valeur de l'avance invalide",
                ],
            ],
            'operateur'  => [
                'rules'  => 'required|is_not_unique[paiement_modes.operateur]',
                'errors' => ['required' => 'Opérateur non défini.', 'is_not_unique' => 'Opérateur invalide'],
            ],
        ];
        $pay_rules = [
            'operateur'  => [
                'rules'  => 'required|is_not_unique[paiement_modes.operateur]',
                'errors' => ['required' => 'Opérateur non défini.', 'is_not_unique' => 'Opérateur invalide'],
            ],
            'telephone'  => [
                'rules'  => 'required|numeric',
                'errors' => ['required' => 'Numéro de téléphone requis pour ce mode de paiement.', 'numeric' => 'Numéro de téléphone invalide.']
            ],
            'returnURL'  => [
                'rules'  => 'required|valid_url',
                'errors' => ['required' => 'L\'URL de retour doit être spécifiée pour ce mode de paiement.', 'valid_url' => 'URL de retour non conforme.']
            ],
            // 'pays'       => [
            //     'rules'  => 'if_exist|is_not_unique[paiement_pays.code]',
            //     'errors' => ['is_not_unique' => 'Pays non pris en charge.'],
            // ],
        ];
        $input = $this->getRequestInput($this->request);

        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
            if ($input['operateur'] != 'PORTE_FEUILLE' && !$this->validate($pay_rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $validationError = $errorsData['code'] == ResponseInterface::HTTP_NOT_ACCEPTABLE;
            $response = [
                'statut'  => 'no',
                'message' => $validationError ? $errorsData['errors'] : "Impossible d'envoyer cette demande.",
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }

        /*
            1- Recupère la transaction
            2- détermine le montant du prochain paiement et compare avec l'avance prévue
            3- effectue le paiement
            4- met à jour les stauts
        */
        $transaction = model("TransactionsModel")->find($input['idtransaction']);
        $ligne = model("LignetransactionsModel")
            ->join("transaction_lignes", "lignetransactions.id=ligne_id", "left")
            ->where("transaction_id", $transaction->id)
            ->first();
        $expectedAmount = $transaction->nextPaymentAmount;
        $amount = $input['avance'];
        $operateurId = model("PaiementModesModel")->where('operateur', $input['operateur'])->findColumn('id')[0];

        if ($expectedAmount < $amount) {
            $response = [
                'statut'  => 'no',
                'message' => "le montant à payer doit être d'aumoins $expectedAmount frs.",
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
        }
        $paiementInfo = [
            'code'      => random_string('alnum', 10),
            'montant'   => $amount,
            'statut'    => PaiementEntity::EN_COURS,
            'mode_id'   => $operateurId,
            'auteur_id' => $this->request->utilisateur->id,
            'statut'    => PaiementEntity::EN_COURS,
            'transaction_id' => $transaction->id,
        ];
        if ($input['operateur'] == 'PORTE_FEUILLE') {
            $portefeuille = model('PortefeuillesModel')->where('utilisateur_id', $this->request->utilisateur->id)->first();
            // effectue le paiement
            try {
                // déduire le montant du portefeuille
                $portefeuille->debit($amount);
            } catch (\Throwable $th) {
                $response = [
                    'statut'  => 'no',
                    'message' => $th->getMessage(),
                ];
                return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
            }
            $paiementInfo['statut'] = PaiementEntity::VALIDE;

            $transaction->reste_a_payer -= $amount;
            $transaction->avance += $amount;
            // mettre à jour les statuts
            if ($transaction->reste_a_payer <= 0) {
                // $transaction->etat = TransactionEntity::$etats[TransactionEntity::TERMINE];
                $transaction->etat = TransactionEntity::TERMINE;
                model("TransactionsModel")->update($transaction->id, $transaction);
                switch ($ligne->produit_group_name) {
                    case 'Avis Expert':
                        model("AvisExpertModel")->update($ligne->produit_id, ["statut" => AvisExpertEntity::EN_COURS]);
                        break;
                    case 'Second Avis':
                        model("ConsultationsModel")->update($ligne->produit_id, ["statut" => ConsultationEntity::VALIDE]);
                        break;
                    case 'Consultation':
                        model("ConsultationsModel")->update($ligne->produit_id, ["statut" => ConsultationEntity::VALIDE]);
                        break;
                    case 'Assurance':
                        model("SouscriptionsModel")->update($ligne->produit_id, ["statut" => SouscriptionsEntity::ACTIF]);
                        break;
                    default:
                        # code...
                        break;
                }
            }
            $message = "Paiement réussi.";
        } else {
            // Initialiser la transaction Monetbill
            $monetbil_args = array(
                'amount'      => $amount,
                'phone'       => $input['telephone'] ?? $this->request->utilisateur->tel1,
                'country'     => $input['pays'] ?? 'CM',
                'phone_lock'  => false,
                'locale'      => 'fr', // Display language fr or en
                'operator'    => $input['operateur'],
                'item_ref'    => $ligne->produit_id,
                'payment_ref' => $paiementInfo['code'],
                'user'        => $this->request->utilisateur->code,
                'return_url'  => $input['returnURL'],
                'notify_url'  => base_url('paiements/notfyTransact'),
                'logo'        => base_url("uploads/images/logoinch.jpeg"),
            );
            // This example show payment url
            $data    = ['url' => \Monetbil::url($monetbil_args)];
            $message = "Paiement Initié.";
        }
        $paiementInfo['id'] = model("PaiementsModel")->insert($paiementInfo);
        $response = [
            'statut'  => 'ok',
            'message' => $message,
            'data'    => [],
        ];
        return $this->sendResponse($response);
    }

    public function localSetTransactPayStatus()
    {
        $rules = [
            'transaction_id' => 'required',
            'item_ref'       => 'required',
            'payment_ref'    => 'required',
            'payment_status' => 'required',
        ];

        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception('');
            }
        } catch (\Throwable $th) {
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $response = [
                'statut'  => 'no',
                'message' => $errorsData['errors'],
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }
        $input = $this->getRequestInput($this->request);

        $item_ref       = $input['item_ref'];
        $payment_ref    = $input['payment_ref'];
        $payment_status = $input['payment_status'];
        $api_transact_id = $input['transaction_id'];

        $transaction = model('TransactionsModel')->where('id', $item_ref)->first();
        $paiement    = model("PaiementsModel")->where("code", $payment_ref)->first();
        if ((!$transaction) || (!$paiement)) {
            $response = [
                'statut'  => 'no',
                'message' => 'Transaction introuvable',
                'data'    => [],
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_EXPECTATION_FAILED);
        }
        $ligne = model("LignetransactionsModel")
            ->join("transaction_lignes", "lignetransactions.id=ligne_id", "left")
            ->where("transaction_id", $transaction->id)
            ->first();

        model("PaiementsModel")->db->transBegin();
        if (Monetbil::STATUS_SUCCESS == $payment_status) {
            // Successful payment!
            $message = "Paiement Réussi.";
            $code    = ResponseInterface::HTTP_OK;
            $transaction->reste_a_payer -= $paiement->montant;
            $transaction->avance += $paiement->montant;
            model("PaiementsModel")->update($paiement->id, ['code' => $api_transact_id, 'statut' => PaiementEntity::VALIDE]);
            if ($transaction->reste_a_payer <= 0) {
                // $transaction->etat = TransactionEntity::$etats[TransactionEntity::TERMINE];
                $transaction->etat = TransactionEntity::TERMINE;
                model("TransactionsModel")->update($transaction->id, $transaction);
                switch ($ligne->produit_group_name) {
                    case 'Avis Expert':
                        model("AvisExpertModel")->update($ligne->produit_id, ["statut" => AvisExpertEntity::EN_COURS]);
                        break;
                    case 'Second Avis':
                        model("ConsultationsModel")->update($ligne->produit_id, ["statut" => ConsultationEntity::VALIDE]);
                        break;
                    case 'Consultation':
                        model("ConsultationsModel")->update($ligne->produit_id, ["statut" => ConsultationEntity::VALIDE]);
                        break;
                    case 'Assurance':
                        model("SouscriptionsModel")->update($ligne->produit_id, ["statut" => SouscriptionsEntity::ACTIF]);
                        break;
                    default:
                        # code...
                        break;
                }
            }
            model("TransactionsModel")->update($transaction->id, ['etat' => TransactionEntity::TERMINE]);
        } elseif (Monetbil::STATUS_CANCELLED == $payment_status) {
            model("PaiementsModel")->update($paiement->id, ['code' => $api_transact_id, 'statut' => PaiementEntity::ANNULE]);
            $message = "Paiement Annulé.";
            $code    = ResponseInterface::HTTP_OK;
        } else {
            model("PaiementsModel")->update($paiement->id, ['code' => $api_transact_id, 'statut' => PaiementEntity::ECHOUE]);
            $message = "Echec du Paiement.";
            $code = ResponseInterface::HTTP_BAD_REQUEST;
        }
        model("PaiementsModel")->db->transCommit();

        $response = [
            'statut'  => 'ok',
            'message' => $message,
            'data'    => [],
        ];
        return $this->sendResponse($response, $code);
    }
}
