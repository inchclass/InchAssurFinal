<?php

namespace Modules\Consultations\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\API\ResponseTrait;
use App\Traits\ControllerUtilsTrait;
use App\Traits\ErrorsDataTrait;
use Modules\Consultations\Entities\LangueEntity;

class LanguesController extends BaseController
{
    use ControllerUtilsTrait;
    use ResponseTrait;
    use ErrorsDataTrait;

    protected $helpers = ['Modules\Documents\Documents', 'Modules\Images\Images', 'text'];

    /**
     * Retourne la liste des langues, ou celle du médecin spécifié.
     *
     * @return ResponseInterface The HTTP response.
     */
    public function index($identifier = null)
    {
        if ($identifier) {
            $identifier = $this->getIdentifier($identifier, 'id');
            $med = model("UtilisateursModel")->where($identifier['name'], $identifier['value'])->first();
            // $langueIDs = model("MedecinLanguesModel")->where("medecin_id", $med->id)->findAll() ?? [];
            $langueIDs = model("MedecinLanguesModel")->where("medecin_id", $med->id)->findcolumn("langue_id");
            // $langueIDs[] = LangueEntity::ID_FRANCAIS;
            if ($langueIDs) {
                $langues = model("LanguesModel")->whereIn("id", $langueIDs)->findAll();
            } else {
                $langues = [];
            }
        } else {
            $langues = model("LanguesModel")->findAll() ?? [];
        }

        $response = [
            'statut'  => 'ok',
            'message' => (count($langues) ? count($langues) : 'Aucune') . ' langue(s) trouvée(s).',
            'data'    => $langues,
        ];
        return $this->sendResponse($response);
    }

    /**
     * Ajoute une langue
     *
     * @return ResponseInterface The HTTP response.
     */
    public function create()
    {
        /* Strictement réservé aux administrateurs */
        if (!auth()->user()->inGroup('administrateur')) {
            $response = [
                'statut'  => 'no',
                'message' => ' Action non authorisée pour ce profil.',
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $rules = [
            'nom' => [
                'rules' => 'required',
                'errors' => ['required' => 'Le nom de la langue est requis.',]
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
                'message' => $validationError ? $errorsData['errors'] : "Impossible d'ajouter cette ville.",
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }
        $input = $this->getRequestInput($this->request);

        model("Languesmodel")->insert(['nom' => $input['nom']]);
        $response = [
            'statut'  => 'ok',
            'message' => 'Langue Ajoutée.',
        ];
        return $this->sendResponse($response);
    }

    /**
     * Modifie une langue
     *
     * @return ResponseInterface The HTTP response.
     */
    public function update($id = null)
    {
        /* Strictement réservé aux administrateurs */
        if (!auth()->user()->inGroup('administrateur')) {
            $response = [
                'statut'  => 'no',
                'message' => ' Action non authorisée pour ce profil.',
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $rules = [
            'nom' => [
                'rules' => 'required',
                'errors' => ['required' => 'Le nom de la langue est requis.',]
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
                'message' => $validationError ? $errorsData['errors'] : "Impossible de modifier cette langue.",
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }

        $input = $this->getRequestInput($this->request);
        model("Languesmodel")->update($id, ['nom' => $input['nom']]);
        $response = [
            'statut'  => 'ok',
            'message' => 'Langue Modifiée.',
        ];
        return $this->sendResponse($response);
    }

    /**
     * Supprime une langue
     *
     * @return ResponseInterface The HTTP response.
     */
    public function delete($id = null)
    {
        /* Strictement réservé aux administrateurs */
        if (!auth()->user()->inGroup('administrateur')) {
            $response = [
                'statut'  => 'no',
                'message' => ' Action non authorisée pour ce profil.',
            ];
            return $this->sendResponse($response, ResponseInterface::HTTP_UNAUTHORIZED);
        }
        model("Languesmodel")->delete($id);
        $response = [
            'statut'  => 'ok',
            'message' => 'Langue Supprimée.',
        ];
        return $this->sendResponse($response);
    }

    /**
     * Associe des langues au médecin spécifié.
     *
     * @param  int|string $medIdentity
     * @return ResponseInterface The HTTP response.
     */
    public function setMedlang($medIdentity)
    {
        $rules = [
            'langues'   => 'required',
            'langues.*' => 'integer|is_not_unique[langues.id]',
        ];
        $input = $this->getRequestInput($this->request);

        $model = model("MedecinLanguesModel");
        try {
            if (!$this->validate($rules)) {
                $hasError = true;
                throw new \Exception();
            }
            $model->db->transBegin();

            foreach ($input['langues'] as $idLangue) {
                $model->insert(["medecin_id" => (int)$medIdentity, "langue_id" => (int)$idLangue]);
            }
            $model->db->transCommit();
            $response = [
                'statut'  => 'ok',
                'message' => "Langue(s) Définie(s) pour le médecin.",
                'data'    => [],
            ];
            return $this->sendResponse($response);
        } catch (\Throwable $th) {
            $model->db->transRollback();
            $errorsData = $this->getErrorsData($th, isset($hasError));
            $response = [
                'statut'  => 'no',
                'message' => "Impossible d'associer ce(s) langue(s) au médecin.",
                'errors'  => $errorsData['errors'],
            ];
            return $this->sendResponse($response, $errorsData['code']);
        }
    }

    /**
     * Supprime une association entre un medecin et une localisation
     *
     * @param  int $idLangue
     * @param  int|string $medIdentity
     * @return ResponseInterface The HTTP response.
     */
    public function delMedLangue($idLangue, $medIdentity)
    {
        $identifier = $this->getIdentifier($medIdentity, 'id');
        $med = model("UtilisateursModel")->where($identifier['name'], $identifier['value'])->first();
        model("MedecinLanguesModel")->where("medecin_id", $med->id)->where("langue_id", $idLangue)->delete();
        $response = [
            'statut'  => 'ok',
            'message' => "Langue retirée pour ce médecin.",
        ];
        return $this->sendResponse($response);
    }
}
