<?php

namespace MBMigration\Layer\Brizy;

use GuzzleHttp\Exception\ConnectException;
use MBMigration\Builder\Utils\FontUtils;
use MBMigration\Core\Logger;
use MBMigration\Layer\Graph\QueryBuilder;
use Psr\Http\Message\ResponseInterface;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use MBMigration\Builder\VariableCache;
use MBMigration\Core\Config;
use MBMigration\Core\Utils;

class BrizyAPI extends Utils
{
    private $projectToken;
    private $nameFolder;
    private $containerID;
    /**
     * @var VariableCache|mixed
     */
    protected $cacheBR;

    private QueryBuilder $QueryBuilder;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        Logger::instance()->debug('BrizyAPI Initialization');
        $this->projectToken = $this->check(Config::$mainToken, 'Config not initialized');
        $this->cacheBR = VariableCache::getInstance();
    }

    /**
     * @throws Exception
     */
    public function getProjectMetadata($projectId)
    {
        $url = $this->createUrlAPI('projects') . '/' . $projectId;

        $result = $this->httpClient('GET', $url);

        $result = json_decode($result['body'], true);

        if (!is_array($result)) {
            return null;
        }
        if ($result['metadata'] === null) {
            return null;
        }

        return json_decode($result['metadata'], true);

    }

    public function getAllProjectFromContainer($containerId, $count = 100)
    {
        $param = [
            'page' => 1,
            'count' => 100,
            'workspace' => $containerId,
        ];

        $url = $this->createUrlAPI('projects');

        $result = $this->httpClient('GET', $url, $param);
        if ($result['status'] > 200) {
            Logger::instance()->warning('Response: ' . json_encode($result));
            Logger::instance()->info('Response: ' . json_encode($result));
            throw new Exception('Bad Response from Brizy: ' . json_encode($result));
        } else {
            return json_decode($result['body'], true);
        }
    }

    public function getAllProjectFromContainerV1($containerId, $count = 100)
    {
        $param = [
            'page' => 1,
            'count' => 100,
            'container' => $containerId,
        ];

        $url = $this->createPrivateUrlAPI('projects');

        $result = $this->httpClient('GET', $url, $param);
        if ($result['status'] > 200) {
            Logger::instance()->warning('Response: ' . json_encode($result));
            Logger::instance()->info('Response: ' . json_encode($result));
            throw new Exception('Bad Response from Brizy: ' . json_encode($result));
        } else {
            return json_decode($result['body'], true);
        }
    }

    public function deleteProject($projectID): bool
    {
        $url = $this->createUrlAPI('projects');
        $response = $this->httpClient('DELETE', $url . "/" . $projectID);
        return $response['status'] == 200;
    }

    public function getProjectHomePage($projectId, $homePageId)
    {
        $url = $this->createUrlProject($projectId);
        $result = $this->httpClient('PUT', $url, ['index_item_id' => $homePageId, 'is_autosave' => false]);
        return $result['status'] == 200;
    }

    /**
     * @throws Exception
     */
    public function getWorkspaces($name = null)
    {
        $result = $this->httpClient('GET', $this->createUrlAPI('workspaces'), ['page' => 1, 'count' => 100]);

        if (!isset($name)) {
            return $result;
        }

        $result = json_decode($result['body'], true);

        if (!is_array($result)) {
            return false;
        }

        foreach ($result as $value) {
            if ($value['name'] === $name) {
                return $value['id'];
            }
        }

        return false;
    }

    /**
     * @throws Exception
     */
    public function getProject($workspacesID, $filtre = null)
    {
        $param = [
            'page' => 1,
            'count' => 100,
            'workspace' => $workspacesID,
        ];

        $result = $this->httpClient('GET', $this->createUrlAPI('projects'), $param);

        if (!isset($filtre)) {
            return $result;
        }

        $result = json_decode($result['body'], true);

        if (!is_array($result)) {
            return false;
        }

        foreach ($result as $value) {
            if ($value['name'] === $filtre) {
                return $value['id'];
            }
        }

        return false;

    }

    public function getProjectPrivateApi($projectID)
    {
        $param = ['project' => $projectID];

        $url = $this->createPrivateUrlAPI('projects') . '/' . $projectID;

        $result = $this->httpClient('GET', $url, $param);

        $result = json_decode($result['body'], true);

        if (!is_array($result)) {
            return false;
        }

        return $result;

    }

    /**
     * @throws Exception
     */
    public function getGraphToken($projectid)
    {
        $nameFunction = __FUNCTION__;

        $result = $this->httpClient('GET', $this->createUrlApiProject($projectid));
        if ($result['status'] > 200) {
            Logger::instance()->warning('Response: ' . json_encode($result));
            Logger::instance()->info('Response: ' . json_encode($result));
            throw new Exception('Bad Response from Brizy: ' . json_encode($result));
        }
        $resultDecode = json_decode($result['body'], true);

        if (!is_array($resultDecode)) {
            Logger::instance()->warning('Bad Response');
            Logger::instance()->info('Bad Response from Brizy' . json_encode($result));
            throw new Exception('Bad Response from Brizy: ' . json_encode($result));
        }
        if (array_key_exists('code', $result)) {
            if ($resultDecode['code'] == 500) {
                Logger::instance()->error('Error getting token');
                Logger::instance()->info('Getting token' . json_encode($result));
                throw new Exception('getting token: ' . json_encode($result));
            }
        }

        return $resultDecode['access_token'];
    }

    /**
     * @throws Exception
     */
    public function getUserToken($userId)
    {
        $result = $this->httpClient('GET', $this->createUrlAPI('users'), ['id' => $userId]);

        $result = json_decode($result['body'], true);

        if (!is_array($result)) {
            return false;
        }

        return $result['token'];
    }

    public function setProjectToken($newToken)
    {
        $this->projectToken = $newToken;
    }

    public function uploadCustomIcon($projectId, $fileName, $attachment)
    {
        try {
            $url = $this->createPrivateUrlAPI('customicons');

            $result = $this->httpClient(
                'POST',
                $url,
                [
                    'filename' => $fileName,
                    'attachment' => base64_encode($attachment),
                    'project' => $projectId,
                ]
            );

            $result = json_decode($result['body'], true);

            if (!is_array($result)) {

                return false;
            }

            return $result;
        } catch (Exception $e) {

            return false;
        }
    }


    /**
     * @throws Exception
     */
    public function createMedia($pathOrUrlToFileName, $nameFolder = '')
    {
        if ($nameFolder != '') {
            $this->nameFolder = $nameFolder;
        }
        $pathToFileName = $this->isUrlOrFile($pathOrUrlToFileName);

        if ($pathToFileName['status'] === false) {
            Logger::instance()->warning('Failed get path image!!! path: ' . $pathOrUrlToFileName);
            return false;
        }

        $mime_type = mime_content_type($pathToFileName['path']);
        Logger::instance()->debug('Mime type image; ' . $mime_type);
        if ($this->getFileExtension($mime_type)) {

            $file_contents = file_get_contents($pathToFileName['path']);
            if (!$file_contents) {
                Logger::instance()->warning('Failed get contents image!!! path: ' . $pathToFileName['path']);
            }
            $base64_content = base64_encode($file_contents);


//             $result = $this->httpClient('POST', $this->createPrivateUrlAPI('media'), [
//                 'filename' => $this->getFileName($pathToFileName['path']),
//                 'name' => $this->getNameHash($base64_content) . '.' . $this->getFileExtension($mime_type),
//                 'attachment' => $base64_content,
//             ]);

            $projectID = $this->cacheBR->get('projectId_Brizy');

            $url = $this->createUrlAPI('projects') . '/' . $projectID . '/media';


            $result = $this->httpClient(
                'POST',
                $url,
                [
                    'type' => 'image',
                    'filename' => $this->getFileName($pathToFileName['path']),
                    'name' => $this->getNameHash($base64_content).'.'.$this->getFileExtension($mime_type),
                    'attachment' => $base64_content,
                ]
            );

//            $result2 = $this->httpClient(
//                'POST',
//                $this->createPrivateUrlAPI('media'),
//                [
//                'filename' => $this->getFileName($pathToFileName['path']),
//                'name' => $this->getNameHash($base64_content).'.'.$this->getFileExtension($mime_type),
//                'attachment' => $base64_content,
//                ]
//            );

            return $result;
        }

        return false;
    }

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    public function createGlobalBlock($data, $position, $rules)
    {
        Logger::instance()->debug('Create Global Block', [$position, $rules]);

        $projectId = Utils::$cache->get('projectId_Brizy');
        $requestData['project'] = $projectId;
        $requestData['status'] = 'publish';
        $requestData['position'] = $position;
        $requestData['rules'] = $rules;
        $requestData['dataVersion'] = 0;
        $requestData['data'] = $data;
        $requestData['meta'] = '{"type":"normal","extraFontStyles":[],"_thumbnailSrc":17339266,"_thumbnailWidth":600,"_thumbnailHeight":138,"_thumbnailTime":1710341890936}';
        $requestData['is_autosave'] = 0;
        $requestData['uid'] = self::generateCharID(12);

        // New endpoint format: /api/projects/{project}/globalblocks
        $url = $this->createPrivateUrlAPI('projects') . '/' . $projectId . '/globalblocks';

        $result = $this->httpClient('POST', $url, $requestData);

        return false;
    }

    public function deletePage($url)
    {
        $requestData['project'] = Utils::$cache->get('projectId_Brizy');
    }

    public function deleteAllGlobalBlocks()
    {
        $projectId = Utils::$cache->get('projectId_Brizy');
        // New endpoint format: /api/projects/{project}/globalblocks
        $url = $this->createPrivateUrlAPI('projects') . '/' . $projectId . '/globalblocks';
        $requestData['project'] = $projectId;
        $requestData['fields'] = ['id', 'uid'];
        $response = $this->httpClient('GET', $url, $requestData);
        if ($response['status'] == 200) {
            $globalBlocks = json_decode($response['body'], true);
            foreach ($globalBlocks as $block) {
                Logger::instance()->debug("Delete global block {$block['id']}");
                // DELETE endpoint: /api/projects/{project}/globalblocks/{id}
                $response = $this->httpClient('DELETE', $url . "/" . $block['id']);
            }
        }
    }

    public function fopenFromURL($url)
    {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
            ),
        ));

        $fileHandle = fopen($url, 'r', false, $context);

        if (!$fileHandle) {
            return false;
        }

        return $fileHandle;
    }

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    public function createFonts($fontsName, $projectID, array $KitFonts, $displayName)
    {
        $fonts = [];
        $__presenceLogged = false;
        foreach ($KitFonts as $fontWeight => $pathToFonts) {
            Logger::instance()->info("Request to Upload font name: $fontsName, font weight: $fontWeight");
            if (!$__presenceLogged) {
                $this->logFontPresenceIfExists($fontsName, $displayName);
                $__presenceLogged = true;
            }
            foreach ($pathToFonts as $pathToFont) {
                $fileExtension = $this->getExtensionFromFileString($pathToFont);

                $pathToFont = __DIR__ . '/../../Builder/Fonts/' . $pathToFont;

                $fonts[] = [
                    'name' => "files[$fontWeight][$fileExtension]",
                    'contents' => fopen($pathToFont, 'r'),
                ];
            }
        }

        $options['multipart'] = array_merge_recursive($fonts, [
            [
                'name' => 'family',
                'contents' => $displayName,
            ],
            [
                'name' => 'uid',
                'contents' => self::generateCharID(36),
            ],
            [
                'name' => 'container',
                'contents' => $projectID,
            ],
        ]);

        try {
            $res = $this->request('POST', $this->createPrivateUrlAPI('fonts'), $options);
            sleep(1);
            $decoded = json_decode($res->getBody()->getContents(), true);
            if (!is_array($decoded)) {
                Logger::instance()->warning('createFonts: unexpected response payload', ['fontsName' => $fontsName]);
            } else {
                Logger::instance()->info('createFonts: API responded', ['fontsName' => $fontsName, 'uid' => $decoded['uid'] ?? null, 'family' => $decoded['family'] ?? null]);
            }
            return $decoded;
        } catch (Exception $e) {
            Logger::instance()->error('createFonts API call failed', ['fontsName' => $fontsName, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function addFontAndUpdateProject(array $data, string $configFonts = 'upload'): string
    {
        Logger::instance()->info('Add font ' . ($data['family'] ?? 'n/a') . ' in project and update project', ['section' => $configFonts]);
        $containerID = Utils::$cache->get('projectId_Brizy');

        $projectFullData = $this->getProjectContainer($containerID, true);

        $projectData = json_decode($projectFullData['data'], true);
        $brzFontId = self::generateCharID(36);

        switch ($configFonts) {
            case 'upload':
                $newData['family'] = $data['family'];
                $newData['files'] = $data['files'];
                $newData['weights'] = $data['weights'];
                $newData['type'] = $data['type'];
                $newData['id'] = $data['uid'];
                $newData['brizyId'] = $brzFontId;

                $projectData['fonts']['upload']['data'][] = $newData;

                $fontId = $data['uid'];
                break;
            case 'google':
                $data['brizyId'] = $brzFontId;
                $projectData['fonts']['google']['data'][] = $data;
                $fontId = FontUtils::convertFontFamily($data['family']);
                break;
            case 'config':
                $data['brizyId'] = $brzFontId;
                $projectData['fonts']['config']['data'][] = $data;
                $fontId = FontUtils::convertFontFamily($data['family']);
                break;
            default:
                Logger::instance()->warning('addFontAndUpdateProject: unknown section', ['section' => $configFonts]);
                $fontId = '';
        }

        $projectFullData['data'] = json_encode($projectData);

        $result = $this->updateProject($projectFullData);
        Logger::instance()->info('Project updated after font add', ['section' => $configFonts, 'brizyId' => $brzFontId, 'fontId' => $fontId]);

        sleep(3);
        $this->checkUpdateFonts($result, $brzFontId, $data['family'] ?? null);

        return $fontId;
    }

    public function checkUpdateFonts(array $projectDataResponse, $brzFontId, $fontNmae = null)
    {
        Logger::instance()->info("checking the response to see if there is a fontName: $fontNmae ");
        foreach ($projectDataResponse['fonts'] as $fontsList) {
            foreach ($fontsList['data'] as $font) {
                if ($font['brizyId'] === $brzFontId) {
                    Logger::instance()->info("The font was successfully added to the project: $brzFontId => " . $font['brizyId']);
                    return;
                }
            }
        }
        Logger::instance()->warning("The font has not been added to the project: $brzFontId");
    }

    private function logFontPresenceIfExists(string $fontsName, string $displayName): void
    {
        try {
            $containerID = Utils::$cache->get('projectId_Brizy');
            if (!$containerID) { return; }

            $projectFullData = $this->getProjectContainer($containerID, true);
            $projectData = json_decode($projectFullData['data'] ?? '{}', true);

            $targets = [];
            $name1 = FontUtils::convertFontFamily($fontsName);
            $name2 = FontUtils::convertFontFamily($displayName);
            $targets[$name1] = true;
            $targets[$name2] = true;

            $sections = ['upload', 'google', 'config'];
            foreach ($sections as $section) {
                if (!isset($projectData['fonts'][$section]['data']) || !is_array($projectData['fonts'][$section]['data'])) {
                    continue;
                }
                foreach ($projectData['fonts'][$section]['data'] as $font) {
                    if (!isset($font['family'])) { continue; }
                    $familyNorm = FontUtils::convertFontFamily($font['family']);
                    if (!isset($targets[$familyNorm])) { continue; }

                    $id = $font['id'] ?? ($font['uid'] ?? null);
                    $brizyId = $font['brizyId'] ?? null;
                    $idStr = $id ? $id : 'n/a';
                    $brizyIdStr = $brizyId ? $brizyId : 'n/a';

                    Logger::instance()->info("Font already present in project: $familyNorm (type: $section), id: $idStr, brizyId: $brizyIdStr");
                    return;
                }
            }
        } catch (Exception $e) {
            // do not interrupt font upload flow; presence check is best-effort
            Logger::instance()->warning('Font presence check failed', ['font' => $fontsName, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @throws GuzzleException
     */
    public function clearAllFontsInProject()
    {
        Logger::instance()->info('clear all fonts in the project');

        $containerID = Utils::$cache->get('projectId_Brizy');

        $projectFullData = $this->getProjectContainer($containerID, true);

        $projectData = json_decode($projectFullData['data'], true);

        $projectData['fonts']['upload']['data'] = [];
        $projectData['fonts']['google']['data'] = [];
        $projectData['fonts']['config']['data'] = json_decode('[{"kind":"webfonts#webfont","family":"Lato","category":"sans-serif","variants":["100","100italic","300","300italic","regular","italic","700","700italic","900","900italic"],"subsets":["latin-ext","latin"],"version":"v15","lastModified":"2019-03-26","files":{"100":"http:\/\/fonts.gstatic.com\/s\/lato\/v15\/S6u8w4BMUTPHh30wWyWrFCbw7A.ttf","300":"http:\/\/fonts.gstatic.com\/s\/lato\/v15\/S6u9w4BMUTPHh7USew-FGC_p9dw.ttf","700":"http:\/\/fonts.gstatic.com\/s\/lato\/v15\/S6u9w4BMUTPHh6UVew-FGC_p9dw.ttf","900":"http:\/\/fonts.gstatic.com\/s\/lato\/v15\/S6u9w4BMUTPHh50Xew-FGC_p9dw.ttf","100italic":"http:\/\/fonts.gstatic.com\/s\/lato\/v15\/S6u-w4BMUTPHjxsIPy-vNiPg7MU0.ttf","300italic":"http:\/\/fonts.gstatic.com\/s\/lato\/v15\/S6u_w4BMUTPHjxsI9w2PHA3s5dwt7w.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/lato\/v15\/S6uyw4BMUTPHvxk6XweuBCY.ttf","italic":"http:\/\/fonts.gstatic.com\/s\/lato\/v15\/S6u8w4BMUTPHjxswWyWrFCbw7A.ttf","700italic":"http:\/\/fonts.gstatic.com\/s\/lato\/v15\/S6u_w4BMUTPHjxsI5wqPHA3s5dwt7w.ttf","900italic":"http:\/\/fonts.gstatic.com\/s\/lato\/v15\/S6u_w4BMUTPHjxsI3wiPHA3s5dwt7w.ttf"},"brizyId":"uzrpsocdxtgrkbxjjxkchqcybpvpzsuvdlji"},{"kind":"webfonts#webfont","family":"Overpass","category":"sans-serif","variants":["100","100italic","200","200italic","300","300italic","regular","italic","600","600italic","700","700italic","800","800italic","900","900italic"],"subsets":["latin","latin-ext"],"version":"v4","lastModified":"2019-07-17","files":{"100":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdB35WCmI96Ajtm81nGU97gxhcJk1s.ttf","200":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdA35WCmI96Ajtm81lqcv7K6BsAikI7.ttf","300":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdA35WCmI96Ajtm81kOcf7K6BsAikI7.ttf","600":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdA35WCmI96Ajtm81l6d_7K6BsAikI7.ttf","700":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdA35WCmI96Ajtm81kedv7K6BsAikI7.ttf","800":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdA35WCmI96Ajtm81kCdf7K6BsAikI7.ttf","900":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdA35WCmI96Ajtm81kmdP7K6BsAikI7.ttf","100italic":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdD35WCmI96Ajtm81Gga7rqwjUMg1siNQ.ttf","200italic":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdC35WCmI96Ajtm81GgaxbL4h8ij1I7LLE.ttf","300italic":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdC35WCmI96Ajtm81Gga3LI4h8ij1I7LLE.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdH35WCmI96Ajtm82GiWdrCwwcJ.ttf","italic":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdB35WCmI96Ajtm81GgU97gxhcJk1s.ttf","600italic":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdC35WCmI96Ajtm81GgawbO4h8ij1I7LLE.ttf","700italic":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdC35WCmI96Ajtm81Gga2LP4h8ij1I7LLE.ttf","800italic":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdC35WCmI96Ajtm81Gga37M4h8ij1I7LLE.ttf","900italic":"http:\/\/fonts.gstatic.com\/s\/overpass\/v4\/qFdC35WCmI96Ajtm81Gga1rN4h8ij1I7LLE.ttf"},"brizyId":"qwhwsomltrpyogspgbomkxquvqsqfdlvcnfo"},{"kind":"webfonts#webfont","family":"Red Hat Text","category":"sans-serif","variants":["regular","italic","500","500italic","700","700italic"],"subsets":["latin","latin-ext"],"version":"v1","lastModified":"2019-07-26","files":{"500":"http:\/\/fonts.gstatic.com\/s\/redhattext\/v1\/RrQIbohi_ic6B3yVSzGBrMxYm4QIG-eFNVmULg.ttf","700":"http:\/\/fonts.gstatic.com\/s\/redhattext\/v1\/RrQIbohi_ic6B3yVSzGBrMxY04IIG-eFNVmULg.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/redhattext\/v1\/RrQXbohi_ic6B3yVSzGBrMxgb60sE8yZPA.ttf","italic":"http:\/\/fonts.gstatic.com\/s\/redhattext\/v1\/RrQJbohi_ic6B3yVSzGBrMxQbacoMcmJPECN.ttf","500italic":"http:\/\/fonts.gstatic.com\/s\/redhattext\/v1\/RrQKbohi_ic6B3yVSzGBrMxQbZ_cGO2BF1yELmgy.ttf","700italic":"http:\/\/fonts.gstatic.com\/s\/redhattext\/v1\/RrQKbohi_ic6B3yVSzGBrMxQbZ-UHu2BF1yELmgy.ttf"},"brizyId":"eytgthrgfzlrrzxlhynabspndabldgdbdjnm"},{"kind":"webfonts#webfont","family":"DM Serif Text","category":"serif","variants":["regular","italic"],"subsets":["latin","latin-ext"],"version":"v3","lastModified":"2019-07-16","files":{"regular":"http:\/\/fonts.gstatic.com\/s\/dmseriftext\/v3\/rnCu-xZa_krGokauCeNq1wWyafOPXHIJErY.ttf","italic":"http:\/\/fonts.gstatic.com\/s\/dmseriftext\/v3\/rnCw-xZa_krGokauCeNq1wWyWfGFWFAMArZKqQ.ttf"},"brizyId":"pujmflqmocbjojknwlnidilgqedjzqftpnrv"},{"kind":"webfonts#webfont","family":"Blinker","category":"sans-serif","variants":["100","200","300","regular","600","700","800","900"],"subsets":["latin","latin-ext"],"version":"v1","lastModified":"2019-07-26","files":{"100":"http:\/\/fonts.gstatic.com\/s\/blinker\/v1\/cIf_MaFatEE-VTaP_E2hZEsCkIt9QQ.ttf","200":"http:\/\/fonts.gstatic.com\/s\/blinker\/v1\/cIf4MaFatEE-VTaP_OGARGEsnIJkWL4.ttf","300":"http:\/\/fonts.gstatic.com\/s\/blinker\/v1\/cIf4MaFatEE-VTaP_IWDRGEsnIJkWL4.ttf","600":"http:\/\/fonts.gstatic.com\/s\/blinker\/v1\/cIf4MaFatEE-VTaP_PGFRGEsnIJkWL4.ttf","700":"http:\/\/fonts.gstatic.com\/s\/blinker\/v1\/cIf4MaFatEE-VTaP_JWERGEsnIJkWL4.ttf","800":"http:\/\/fonts.gstatic.com\/s\/blinker\/v1\/cIf4MaFatEE-VTaP_ImHRGEsnIJkWL4.ttf","900":"http:\/\/fonts.gstatic.com\/s\/blinker\/v1\/cIf4MaFatEE-VTaP_K2GRGEsnIJkWL4.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/blinker\/v1\/cIf9MaFatEE-VTaPxCmrYGkHgIs.ttf"},"brizyId":"yhkoopjikembswaygkzktfmiiashwjcrvbxr"},{"kind":"webfonts#webfont","family":"Aleo","category":"serif","variants":["300","300italic","regular","italic","700","700italic"],"subsets":["latin","latin-ext"],"version":"v3","lastModified":"2019-07-16","files":{"300":"http:\/\/fonts.gstatic.com\/s\/aleo\/v3\/c4mg1nF8G8_syKbr9DVDno985KM.ttf","700":"http:\/\/fonts.gstatic.com\/s\/aleo\/v3\/c4mg1nF8G8_syLbs9DVDno985KM.ttf","300italic":"http:\/\/fonts.gstatic.com\/s\/aleo\/v3\/c4mi1nF8G8_swAjxeDdJmq159KOnWA.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/aleo\/v3\/c4mv1nF8G8_s8ArD0D1ogoY.ttf","italic":"http:\/\/fonts.gstatic.com\/s\/aleo\/v3\/c4mh1nF8G8_swAjJ1B9tkoZl_Q.ttf","700italic":"http:\/\/fonts.gstatic.com\/s\/aleo\/v3\/c4mi1nF8G8_swAjxaDBJmq159KOnWA.ttf"},"brizyId":"ucgecsrbcjkpsfctgzwsocokuydcdgiubroh"},{"kind":"webfonts#webfont","family":"Nunito","category":"sans-serif","variants":["200","200italic","300","300italic","regular","italic","600","600italic","700","700italic","800","800italic","900","900italic"],"subsets":["latin","vietnamese","latin-ext"],"version":"v11","lastModified":"2019-07-22","files":{"200":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXW3I6Li01BKofA-sekZuHJeTsfDQ.ttf","300":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXW3I6Li01BKofAnsSkZuHJeTsfDQ.ttf","600":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXW3I6Li01BKofA6sKkZuHJeTsfDQ.ttf","700":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXW3I6Li01BKofAjsOkZuHJeTsfDQ.ttf","800":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXW3I6Li01BKofAksCkZuHJeTsfDQ.ttf","900":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXW3I6Li01BKofAtsGkZuHJeTsfDQ.ttf","200italic":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXQ3I6Li01BKofIMN5MZ-vNWz4PDWtj.ttf","300italic":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXQ3I6Li01BKofIMN4oZOvNWz4PDWtj.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXV3I6Li01BKof4MuyAbsrVcA.ttf","italic":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXX3I6Li01BKofIMOaETM_FcCIG.ttf","600italic":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXQ3I6Li01BKofIMN5cYuvNWz4PDWtj.ttf","700italic":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXQ3I6Li01BKofIMN44Y-vNWz4PDWtj.ttf","800italic":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXQ3I6Li01BKofIMN4kYOvNWz4PDWtj.ttf","900italic":"http:\/\/fonts.gstatic.com\/s\/nunito\/v11\/XRXQ3I6Li01BKofIMN4AYevNWz4PDWtj.ttf"},"brizyId":"ppzycxqtiwtmjnfpbfluoynrnnfviuerjczz"},{"kind":"webfonts#webfont","family":"Knewave","category":"display","variants":["regular"],"subsets":["latin","latin-ext"],"version":"v8","lastModified":"2019-07-16","files":{"regular":"http:\/\/fonts.gstatic.com\/s\/knewave\/v8\/sykz-yx0lLcxQaSItSq9-trEvlQ.ttf"},"brizyId":"jojwyelvgkjknbgrosxcdphkpqfcczzdlcen"},{"kind":"webfonts#webfont","family":"Palanquin","category":"sans-serif","variants":["100","200","300","regular","500","600","700"],"subsets":["devanagari","latin","latin-ext"],"version":"v5","lastModified":"2019-07-16","files":{"100":"http:\/\/fonts.gstatic.com\/s\/palanquin\/v5\/9XUhlJ90n1fBFg7ceXwUEltI7rWmZzTH.ttf","200":"http:\/\/fonts.gstatic.com\/s\/palanquin\/v5\/9XUilJ90n1fBFg7ceXwUvnpoxJuqbi3ezg.ttf","300":"http:\/\/fonts.gstatic.com\/s\/palanquin\/v5\/9XUilJ90n1fBFg7ceXwU2nloxJuqbi3ezg.ttf","500":"http:\/\/fonts.gstatic.com\/s\/palanquin\/v5\/9XUilJ90n1fBFg7ceXwUgnhoxJuqbi3ezg.ttf","600":"http:\/\/fonts.gstatic.com\/s\/palanquin\/v5\/9XUilJ90n1fBFg7ceXwUrn9oxJuqbi3ezg.ttf","700":"http:\/\/fonts.gstatic.com\/s\/palanquin\/v5\/9XUilJ90n1fBFg7ceXwUyn5oxJuqbi3ezg.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/palanquin\/v5\/9XUnlJ90n1fBFg7ceXwsdlFMzLC2Zw.ttf"},"brizyId":"xnikbaszrjutnnfixmtprduwstoziivqiflp"},{"kind":"webfonts#webfont","family":"Palanquin Dark","category":"sans-serif","variants":["regular","500","600","700"],"subsets":["devanagari","latin","latin-ext"],"version":"v6","lastModified":"2019-07-16","files":{"500":"http:\/\/fonts.gstatic.com\/s\/palanquindark\/v6\/xn76YHgl1nqmANMB-26xC7yuF8Z6ZW41fcvN2KT4.ttf","600":"http:\/\/fonts.gstatic.com\/s\/palanquindark\/v6\/xn76YHgl1nqmANMB-26xC7yuF8ZWYm41fcvN2KT4.ttf","700":"http:\/\/fonts.gstatic.com\/s\/palanquindark\/v6\/xn76YHgl1nqmANMB-26xC7yuF8YyY241fcvN2KT4.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/palanquindark\/v6\/xn75YHgl1nqmANMB-26xC7yuF_6OTEo9VtfE.ttf"},"brizyId":"gqzfchsrosvxegeymkyugyofaztsitibprrf"},{"kind":"webfonts#webfont","family":"Roboto","category":"sans-serif","variants":["100","100italic","300","300italic","regular","italic","500","500italic","700","700italic","900","900italic"],"subsets":["greek-ext","latin","cyrillic-ext","vietnamese","latin-ext","greek","cyrillic"],"version":"v20","lastModified":"2019-07-24","files":{"100":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOkCnqEu92Fr1MmgWxPKTM1K9nz.ttf","300":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOlCnqEu92Fr1MmSU5vAx05IsDqlA.ttf","500":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOlCnqEu92Fr1MmEU9vAx05IsDqlA.ttf","700":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOlCnqEu92Fr1MmWUlvAx05IsDqlA.ttf","900":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOlCnqEu92Fr1MmYUtvAx05IsDqlA.ttf","100italic":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOiCnqEu92Fr1Mu51QrIzcXLsnzjYk.ttf","300italic":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOjCnqEu92Fr1Mu51TjARc9AMX6lJBP.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOmCnqEu92Fr1Me5WZLCzYlKw.ttf","italic":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOkCnqEu92Fr1Mu52xPKTM1K9nz.ttf","500italic":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOjCnqEu92Fr1Mu51S7ABc9AMX6lJBP.ttf","700italic":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOjCnqEu92Fr1Mu51TzBhc9AMX6lJBP.ttf","900italic":"http:\/\/fonts.gstatic.com\/s\/roboto\/v20\/KFOjCnqEu92Fr1Mu51TLBBc9AMX6lJBP.ttf"},"brizyId":"wrqenoprsynrjiyxmfoeuwqddlnomrxemeec"},{"kind":"webfonts#webfont","family":"Oswald","category":"sans-serif","variants":["200","300","regular","500","600","700"],"subsets":["latin","cyrillic-ext","vietnamese","latin-ext","cyrillic"],"version":"v24","lastModified":"2019-07-23","files":{"200":"http:\/\/fonts.gstatic.com\/s\/oswald\/v24\/TK3_WkUHHAIjg75cFRf3bXL8LICs13FvgUFoZAaRliE.ttf","300":"http:\/\/fonts.gstatic.com\/s\/oswald\/v24\/TK3_WkUHHAIjg75cFRf3bXL8LICs169vgUFoZAaRliE.ttf","500":"http:\/\/fonts.gstatic.com\/s\/oswald\/v24\/TK3_WkUHHAIjg75cFRf3bXL8LICs18NvgUFoZAaRliE.ttf","600":"http:\/\/fonts.gstatic.com\/s\/oswald\/v24\/TK3_WkUHHAIjg75cFRf3bXL8LICs1y9ogUFoZAaRliE.ttf","700":"http:\/\/fonts.gstatic.com\/s\/oswald\/v24\/TK3_WkUHHAIjg75cFRf3bXL8LICs1xZogUFoZAaRliE.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/oswald\/v24\/TK3_WkUHHAIjg75cFRf3bXL8LICs1_FvgUFoZAaRliE.ttf"},"brizyId":"ehiobdhupkijoltxyucnkenojglortpsupmp"},{"kind":"webfonts#webfont","family":"Oxygen","category":"sans-serif","variants":["300","regular","700"],"subsets":["latin","latin-ext"],"version":"v9","lastModified":"2019-07-22","files":{"300":"http:\/\/fonts.gstatic.com\/s\/oxygen\/v9\/2sDcZG1Wl4LcnbuCJW8Db2-4C7wFZQ.ttf","700":"http:\/\/fonts.gstatic.com\/s\/oxygen\/v9\/2sDcZG1Wl4LcnbuCNWgDb2-4C7wFZQ.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/oxygen\/v9\/2sDfZG1Wl4Lcnbu6iUcnZ0SkAg.ttf"},"brizyId":"gzhhqjoyiaozuhrmbylqeknkdaqtxfdynaqt"},{"kind":"webfonts#webfont","family":"Playfair Display","category":"serif","variants":["regular","italic","700","700italic","900","900italic"],"subsets":["latin","vietnamese","latin-ext","cyrillic"],"version":"v15","lastModified":"2019-07-22","files":{"700":"http:\/\/fonts.gstatic.com\/s\/playfairdisplay\/v15\/nuFlD-vYSZviVYUb_rj3ij__anPXBYf9pWkU5xxiJKY.ttf","900":"http:\/\/fonts.gstatic.com\/s\/playfairdisplay\/v15\/nuFlD-vYSZviVYUb_rj3ij__anPXBb__pWkU5xxiJKY.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/playfairdisplay\/v15\/nuFiD-vYSZviVYUb_rj3ij__anPXPTvSgWE_-xU.ttf","italic":"http:\/\/fonts.gstatic.com\/s\/playfairdisplay\/v15\/nuFkD-vYSZviVYUb_rj3ij__anPXDTnYhUM66xV7PQ.ttf","700italic":"http:\/\/fonts.gstatic.com\/s\/playfairdisplay\/v15\/nuFnD-vYSZviVYUb_rj3ij__anPXDTngOWwe4z5nNKaV_w.ttf","900italic":"http:\/\/fonts.gstatic.com\/s\/playfairdisplay\/v15\/nuFnD-vYSZviVYUb_rj3ij__anPXDTngAW4e4z5nNKaV_w.ttf"},"brizyId":"bvbbabnggnnjzvtleuwdrnfuvssxrgeovjan"},{"kind":"webfonts#webfont","family":"Fira Sans","category":"sans-serif","variants":["100","100italic","200","200italic","300","300italic","regular","italic","500","500italic","600","600italic","700","700italic","800","800italic","900","900italic"],"subsets":["greek-ext","latin","cyrillic-ext","vietnamese","latin-ext","greek","cyrillic"],"version":"v10","lastModified":"2019-07-22","files":{"100":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9C4kDNxMZdWfMOD5Vn9IjOazP3dUTP.ttf","200":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9B4kDNxMZdWfMOD5VnWKnuQR37fF3Wlg.ttf","300":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9B4kDNxMZdWfMOD5VnPKruQR37fF3Wlg.ttf","500":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9B4kDNxMZdWfMOD5VnZKvuQR37fF3Wlg.ttf","600":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9B4kDNxMZdWfMOD5VnSKzuQR37fF3Wlg.ttf","700":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9B4kDNxMZdWfMOD5VnLK3uQR37fF3Wlg.ttf","800":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9B4kDNxMZdWfMOD5VnMK7uQR37fF3Wlg.ttf","900":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9B4kDNxMZdWfMOD5VnFK_uQR37fF3Wlg.ttf","100italic":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9A4kDNxMZdWfMOD5VvkrCqYTfVcFTPj0s.ttf","200italic":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9f4kDNxMZdWfMOD5VvkrAGQBf_XljGllLX.ttf","300italic":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9f4kDNxMZdWfMOD5VvkrBiQxf_XljGllLX.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9E4kDNxMZdWfMOD5VfkILKSTbndQ.ttf","italic":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9C4kDNxMZdWfMOD5VvkojOazP3dUTP.ttf","500italic":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9f4kDNxMZdWfMOD5VvkrA6Qhf_XljGllLX.ttf","600italic":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9f4kDNxMZdWfMOD5VvkrAWRRf_XljGllLX.ttf","700italic":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9f4kDNxMZdWfMOD5VvkrByRBf_XljGllLX.ttf","800italic":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9f4kDNxMZdWfMOD5VvkrBuRxf_XljGllLX.ttf","900italic":"http:\/\/fonts.gstatic.com\/s\/firasans\/v10\/va9f4kDNxMZdWfMOD5VvkrBKRhf_XljGllLX.ttf"},"brizyId":"wndeuiwznzaqgsugjnojbhzjhjwtryegciis"},{"kind":"webfonts#webfont","family":"Abril Fatface","category":"display","variants":["regular"],"subsets":["latin","latin-ext"],"version":"v11","lastModified":"2019-07-17","files":{"regular":"http:\/\/fonts.gstatic.com\/s\/abrilfatface\/v11\/zOL64pLDlL1D99S8g8PtiKchm-BsjOLhZBY.ttf"},"brizyId":"fbyhozjmiqseimmgxerwiucacmaaljqitrdc"},{"kind":"webfonts#webfont","family":"Comfortaa","category":"display","variants":["300","regular","500","600","700"],"subsets":["latin","cyrillic-ext","vietnamese","latin-ext","greek","cyrillic"],"version":"v23","lastModified":"2019-07-17","files":{"300":"http:\/\/fonts.gstatic.com\/s\/comfortaa\/v23\/1Pt_g8LJRfWJmhDAuUsSQamb1W0lwk4S4TbMPrQVIT9c2c8.ttf","500":"http:\/\/fonts.gstatic.com\/s\/comfortaa\/v23\/1Pt_g8LJRfWJmhDAuUsSQamb1W0lwk4S4VrMPrQVIT9c2c8.ttf","600":"http:\/\/fonts.gstatic.com\/s\/comfortaa\/v23\/1Pt_g8LJRfWJmhDAuUsSQamb1W0lwk4S4bbLPrQVIT9c2c8.ttf","700":"http:\/\/fonts.gstatic.com\/s\/comfortaa\/v23\/1Pt_g8LJRfWJmhDAuUsSQamb1W0lwk4S4Y_LPrQVIT9c2c8.ttf","regular":"http:\/\/fonts.gstatic.com\/s\/comfortaa\/v23\/1Pt_g8LJRfWJmhDAuUsSQamb1W0lwk4S4WjMPrQVIT9c2c8.ttf"},"brizyId":"plspcdzrrelkhthvkmoocpwrtltvuzqcyraw"},{"kind":"webfonts#webfont","family":"Kaushan Script","category":"handwriting","variants":["regular"],"subsets":["latin","latin-ext"],"version":"v8","lastModified":"2019-07-17","files":{"regular":"http:\/\/fonts.gstatic.com\/s\/kaushanscript\/v8\/vm8vdRfvXFLG3OLnsO15WYS5DF7_ytN3M48a.ttf"},"brizyId":"simpmqjphttgbnwqaobwxuxoavrdlbpdjgzc"}]', true);

        $url = $this->createPrivateUrlAPI('projects') . '/' . $containerID;

        $r_projectFullData['data'] = json_encode($projectData);
        $r_projectFullData['is_autosave'] = 0;
//        $r_projectFullData['dataVersion'] = $projectFullData["dataVersion"] + 1;

        $this->request('PUT', $url, ['form_params' => $r_projectFullData]);
    }

    public function setLabelManualMigration(bool $value, $projectID = null)
    {
        Logger::instance()->info('set Label Manual Migration');

        if (empty($projectID)) {
            $containerID = Utils::$cache->get('projectId_Brizy');
        } else {
            $containerID = $projectID;
        }

        $url = $this->createPrivateUrlAPI('projects') . '/' . $containerID;

        if ($value) {
            $r_projectFullData['dataVersion'] = 700;
        } else {
            $r_projectFullData['dataVersion'] = 1;
        }

        $this->request('PUT', $url, ['form_params' => $r_projectFullData]);
    }

    public function setCloningLink(bool $value, $projectID = null)
    {
        Logger::instance()->info('set Label Manual Migration');

        if (empty($projectID)) {
            $containerID = Utils::$cache->get('projectId_Brizy');
        } else {
            $containerID = $projectID;
        }

        $url = $this->createPrivateUrlAPI('projects') . '/' . $containerID . '/cloning_link';


        if ($value) {
            $r_projectFullData['enabled'] = 1;
        } else {
            $r_projectFullData['enabled'] = 0;
        }

        $r_projectFullData['regenerate'] = 0;

        try{
            $return = $this->request('PUT', $url, ['form_params' => $r_projectFullData]);
        } catch(\Exception $e) {
            $ddd = $e;
        }
    }

    public function updateProject(array $projectFullData): array
    {
        Logger::instance()->info('Update Project Data');
        $containerID = Utils::$cache->get('projectId_Brizy');
        $url = $this->createPrivateUrlAPI('projects') . '/' . $containerID;

        $r_projectFullData['is_autosave'] = 0;
//        $r_projectFullData['dataVersion'] = $projectFullData["dataVersion"] + 1;
        $r_projectFullData['data'] = $projectFullData['data'];

        $result = $this->request('PUT', $url, ['form_params' => $r_projectFullData]);
        $body = json_decode($result->getBody(), true);
        return json_decode($body['data'] ?? '', true);
    }

    public function getMetadata(): array
    {
        $containerID = Utils::$cache->get('projectId_Brizy');
        $url = $this->createUrlAPI('projects') . '/' . $containerID;

        $r_projectFullData['project'] = $containerID;

        $result = $this->request('GET', $url, ['form_params' => $r_projectFullData]);

        $result = json_decode($result->getBody(), true);

        if (!empty($result['metadata'])) {

            return json_decode($result['metadata'], true);
        }

        return ['site_id' => '', 'secret' => ''];
    }


    /**
     * @throws GuzzleException
     */
    public function setMetaDate()
    {
        Logger::instance()->info('Check metaDate settings');
        if (Config::$metaData) {

            Logger::instance()->info('Create links between projects');

            $projectId_Brizy = Utils::$cache->get('projectId_Brizy');

            $metadata['site_id'] = Config::$metaData['mb_site_id'];
            $metadata['secret'] = Config::$metaData['mb_secret'];
            $metadata['MBAccountID'] = Config::$metaData['MBAccountID'] ?? '';
            $metadata['MBVisitorID'] = Config::$metaData['MBVisitorID'] ?? '';
            $metadata['MBThemeName'] = Utils::$cache->get('design', 'settings');

            $url = $this->createUrlAPI('projects') . '/' . $projectId_Brizy;

            $result = $this->request('PATCH', $url, ['form_params' => ['metadata' => json_encode($metadata)]]);
            $statusCode = $result->getStatusCode();
        }
    }

    private function getExtensionFromFileString($fileString)
    {
        $parts = explode('/', $fileString);
        $filename = end($parts);

        return pathinfo($filename, PATHINFO_EXTENSION);
    }

    /**
     * @throws Exception
     */
    public function getProjectContainer(int $containerID, $fullDataProject = false)
    {
        $url = $this->createPrivateUrlAPI('projects');
        $result = $this->httpClient('GET_P', $url, $containerID);
        if (!$fullDataProject) {
            if ($result['status'] === 200) {
                $response = json_decode($result['body'], true);

                return $response['container'];
            }
        }

        return json_decode($result['body'], true);
    }

    /**
     * @throws Exception
     */
    public function createUser(array $value)
    {
        $result = $this->httpClient('POST', $this->createUrlAPI('users'), $value);

        $result = json_decode($result['body'], true);

        if (!is_array($result)) {
            return false;
        }

        return $result['token'];

    }

    /**
     * @throws Exception
     */
    public function createProject($projectName, $workspacesId, $filter = null)
    {
        $result = $this->httpClient('POST', $this->createUrlAPI('projects'), [
            'name' => $projectName,
            'workspace' => $workspacesId,
        ]);

        if (!isset($filter)) {
            return $result;
        }

        $result = json_decode($result['body'], true);

        if (!is_array($result)) {
            return false;
        }

        return $result[$filter];
    }

    public function clearCompileds($projectId)
    {
        $result = $this->httpClient('POST', $this->createPrivateUrlAPI('projects') . '/' . $projectId . '/clearcompileds', [
            'project' => $projectId,
        ]);

        if (!isset($filter)) {
            return $result;
        }

        $result = json_decode($result['body'], true);

        if (!is_array($result)) {
            return false;
        }

        return $result[$filter];
    }


    /**
     * Создать новый workspace
     * 
     * @param string|null $name Имя workspace. Если не указано, используется Config::$nameMigration
     * @return array
     * @throws Exception
     */
    public function createdWorkspaces(?string $name = null): array
    {
        $workspaceName = $name ?? Config::$nameMigration;
        return $this->httpClient('POST', $this->createUrlAPI('workspaces'), ['name' => $workspaceName]);
    }

    /**
     * @throws Exception
     */
    public function getPage($projectID)
    {
        $param = [
            'page' => 1,
            'count' => 100,
            'project' => $projectID,
        ];

        $result = $this->httpClient('GET', $this->createPrivateUrlAPI('pages'), $param);

        if (!isset($filtre)) {
            return $result;
        }

        $result = json_decode($result['body'], true);

        if (!is_array($result)) {
            return false;
        }

        foreach ($result as $value) {
            if ($value['name'] === $filtre) {
                return $value['id'];
            }
        }

        return false;

    }

    public function getDomain($projectID)
    {
        $url = $this->createPrivateUrlAPI('projects') . '/' . $projectID . '/domain';
        $result = $this->httpClient('GET', $url);

        $value = json_decode($result['body'], true);

        if (!empty($value['name'])) {
            return $value['name'];
        }

        return false;
    }

    public function checkProjectManualMigration($projectID): bool
    {
        $result = $this->getProjectsDataVersion($projectID);
        if ($result >= 700) {

            return true;
        }

        return false;
    }

    public function getProjectsDataVersion($projectID)
    {
        $url = $this->createPrivateUrlAPI('projects') . '/' . $projectID;

        try {
            $result = $this->httpClient('GET', $url);

            $value = json_decode($result['body'], true);

            if (!empty($value['dataVersion'])) {
                return $value['dataVersion'];
            }
        } catch (Exception $e) {
            return false;
        }

        return false;
    }

    public function getProjectsData($projectID)
    {
        $url = $this->createPrivateUrlAPI('projects') . '/' . $projectID;

        try {
            $result = $this->httpClient('GET', $url);

            $value = json_decode($result['body'], true);

            if (!empty($value)) {
                return $value;
            }
        } catch (Exception $e) {
            return false;
        }

        return false;
    }

    /**
     * @throws Exception
     */
    public function createPage($projectID, $pageName, $filter = null)
    {
        $result = $this->httpClient('POST', $this->createPrivateUrlAPI('pages'), [
            'project' => $projectID,
            'dataVersion' => '2.0',
            'data' => $pageName,
            'is_index' => false,
            'status' => 'draft',
            'type' => 'page',
            'is_autosave' => true,
        ]);

        if (!isset($filter)) {
            return $result;
        }

        $result = json_decode($result['body'], true);

        if (!is_array($result)) {
            return false;
        }

        return $result[$filter];
    }

    public function getAllProjectPages(): array
    {
        static $result;

        if (!empty($result)) {
            return $result;
        }

        $this->QueryBuilder = $this->cacheBR->getClass('QueryBuilder');

        $collectionTypes = $this->QueryBuilder->getCollectionTypes($this->cacheBR->get('projectId_Brizy'));;

        $foundCollectionTypes = [];
        $entities = [];

        foreach ($collectionTypes as $collectionType) {
            if ($collectionType['slug'] == 'page') {
                $foundCollectionTypes[$collectionType['slug']] = $collectionType['id'];
                $result['mainCollectionType'] = $collectionType['id'];
            }
        }

        $collectionItems = $this->QueryBuilder->getCollectionItems($foundCollectionTypes);

        foreach ($collectionItems['page']['collection'] as $entity) {
            $entities[$entity['slug']] = $entity['id'];
        }
        $result['listPages'] = $entities;

        return $result;
    }

    /**
     * @throws Exception
     */
    public function createMenu($data)
    {
        Logger::instance()->info('Request to create menu');
        $result = $this->httpClient('POST', $this->createPrivateUrlAPI('menu'), [
            'project' => $data['project'],
            'name' => $data['name'],
            'data' => $data['data'],
        ]);
        if ($result['status'] !== 201) {
            Logger::instance()->warning('Failed menu');

            return false;
        }
        Logger::instance()->info('Created menu');

        return json_decode($result['body'], true);
    }

    private function createUrlApiProject($projectId): string
    {
        return Utils::strReplace(Config::$urlGetApiToken, '{project}', $projectId);
    }

    private function createUrlAPI($endPoint): string
    {
        return Config::$urlAPI . Config::$endPointVersion . Config::$endPointApi[$endPoint];
    }

    private function createUrlProject($projectId, $endPoint = ''): string
    {
        $urlProjectAPI = Utils::strReplace(Config::$urlProjectAPI, '{project}', $projectId);

        return $urlProjectAPI;
    }

    private function createPrivateUrlAPI($endPoint): string
    {
        return Config::$urlAPI . Config::$endPointApi[$endPoint];
    }

    private function createUrl($endPoint): string
    {
        return Config::$cloud_host. Config::$endPointApi[$endPoint];
    }

    public function cloneProject($projectId, $workspaceId)
    {
        try {
            $url = $this->createUrlAPI('projects') . '/' . $projectId . '/duplicates';

            $result = $this->httpClient('POST', $url, [
                'workspace' => $workspaceId,
            ]);

            $result = json_decode($result['body'], true);

            return $result;

        } catch (exception $e) {
            return false;
        }
    }

    public function upgradeProject($projectId)
    {
        try {
            $url = $this->createUrl('projects') . '/' . $projectId . '/upgrade';

            $result = $this->httpClient('GET', $url);

            $result = json_decode($result['body'], true);

            return $result;

        } catch (exception $e) {
            return false;
        }

    }

    public function setMediaFolder(string $nameFolder)
    {
        $this->nameFolder = $nameFolder;
    }


    private function generateUniqueID(): string
    {
        $microtime = microtime();
        $microtime = str_replace('.', '', $microtime);
        $microtime = substr($microtime, 0, 10);
        $random_number = rand(1000, 9999);

        return $microtime . $random_number;
    }

    private function generateUID(): string
    {
        return $this->getNameHash($this->generateUniqueID());
    }

    private function getFileExtension($mime_type)
    {
        $extensions = array(
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
        );

        return $extensions[$mime_type] ?? false;
    }

    private function getFileName($string)
    {
        $parts = pathinfo($string);
        if (isset($parts['extension'])) {
            return $parts['basename'];
        } else {
            return $string;
        }
    }

    /**
     * @throws Exception
     */
    private function downloadImage($url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $image_data = curl_exec($ch);
        curl_close($ch);

        if ($image_data === false) {
            Logger::instance()->warning('Failed to download image from URL: ' . $url);
            return ['status' => false];
        }

        $file_name = mb_strtolower(basename($url));
        $fileNameParts = explode(".", $file_name);

        if (count($fileNameParts) < 2) {
            Logger::instance()->warning('Invalid file name format: ' . $file_name);
            return ['status' => false];
        }

        $path = Config::$pathTmp . $this->nameFolder . '/media/' . $fileNameParts[0];

        file_put_contents($path, $image_data);

        if (!file_exists($path)) {
            Logger::instance()->warning('Failed to save image to path: ' . $path);
            return ['status' => false];
        }

        $currentExtensionImage = $fileNameParts[count($fileNameParts) - 1];

        switch ($currentExtensionImage) {
            case 'jfif':
                $extensionImage = $this->getFileExtension(
                    mime_content_type($path)
                );
                break;
            default:
                $extensionImage = $currentExtensionImage;
        }

        $newDetailsImage = $this->convertImageFormat($path, $extensionImage);

        if ($newDetailsImage['status'] === false) {
            Logger::instance()->warning('Failed to convert image format: ' . $path);
            return ['status' => false];
        }

        $this->resizeImageIfNeeded($newDetailsImage['path'], 9.5);

        return [
            'status' => true,
            'originalExtension' => $fileNameParts[count($fileNameParts) - 1],
            'fileName' => $fileNameParts[0] . '.' . $newDetailsImage['fileType'],
            'path' => $newDetailsImage['path'],
        ];
    }

    /**
     * @throws Exception
     */
    private function convertImageFormat($filePath, $targetExtension): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            Logger::instance()->warning('File not found or not readable: ' . $filePath);
            return ['status' => false];
        }

        $image = file_get_contents($filePath);

        if ($image === false) {
            Logger::instance()->warning('Failed to create image resource for conversion: ' . $filePath);
            return ['status' => false];
        }

        $newFilePath = $filePath . '.' . $targetExtension;
        $saveResult = (bool)file_put_contents($newFilePath, $image);

        if ($saveResult) {
            if (!unlink($filePath)) {
                Logger::instance()->warning('Failed to delete original file: ' . $filePath);
            }
        } else {
            Logger::instance()->warning('Failed to save image in target format: ' . $newFilePath);
            return ['status' => false];
        }

        return [
            'status' => true,
            'fileType' => $targetExtension,
            'path' => $newFilePath
        ];
    }

    /**
     * @throws Exception
     */
    private function resizeImageIfNeeded($filePath, $maxSizeMB = 10): void
    {
        $maxSizeBytes = $maxSizeMB * 1024 * 1024;

        if (!file_exists($filePath)) {
            Logger::instance()->warning('Compression file not found: ' . $filePath);

            return;
        }

        $fileSize = filesize($filePath);
        if ($fileSize <= $maxSizeBytes) {

            return;
        }

        $imageInfo = getimagesize($filePath);
        if (!$imageInfo) {
            Logger::instance()->warning('The file is not an image: ' . $filePath);
            return;
        }

        list($width, $height, $type) = $imageInfo;

        switch ($type) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($filePath);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($filePath);
                break;
            case IMAGETYPE_WEBP:
                $image = imagecreatefromwebp($filePath);
                break;
            default:
                Logger::instance()->warning('The image type is not supported: ' . $filePath);
                return;
        }

        $scaleFactor = sqrt($maxSizeBytes / $fileSize);

        $newWidth = (int)($width * $scaleFactor);
        $newHeight = (int)($height * $scaleFactor);

        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
        }

        imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($resizedImage, $filePath, 90);
                break;
            case IMAGETYPE_PNG:
                imagepng($resizedImage, $filePath, 9);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($resizedImage, $filePath, 90);
                break;
        }

        imagedestroy($image);
        imagedestroy($resizedImage);

        Logger::instance()->info('The image has been compressed to an acceptable size: ' . $filePath);

    }

    private function fileExtension($expansion): string
    {
        $expansionMap = [
            "jpeg" => "jpg",
        ];

        if (array_key_exists($expansion, $expansionMap)) {
            return $expansionMap[$expansion];
        }

        return $expansion;
    }

    private function readBinaryFile($filename)
    {
        $handle = fopen($filename, 'rb');
        if ($handle === false) {
            return false;
        }
        $data = fread($handle, filesize($filename));
        fclose($handle);

        return $data;
    }

    /**
     * @throws Exception
     */
    private function isUrlOrFile(string $urlOrPath): array
    {
        if (filter_var($urlOrPath, FILTER_VALIDATE_URL)) {

            $detailsImage = $this->downloadImage($urlOrPath);

            if ($detailsImage['status'] === false) {
                return ['status' => false, 'message' => 'Failed to download image'];
            }
            return $detailsImage;

        } else {
            if (file_exists($urlOrPath)) {
                return ['status' => false, 'message' => 'Failed to download image'];
            } else {
                return ['status' => false, 'message' => 'Failed to download image'];
            }
        }
    }

    public function createDirectory($directoryPath): void
    {
        if (!is_dir($directoryPath)) {
            mkdir($directoryPath, 0777, true);
        }
    }

    /**
     * @throws GuzzleException
     */
    private function request(
        string $method,
        string $uri = '',
        array  $options = [],
               $contentType = null,
        int    $retryAttempts = 3
    ): ?ResponseInterface
    {
        $client = new Client();
        $headers = [
            'x-auth-user-token' => Config::$mainToken,
        ];

        if (in_array($method, ['PUT', 'POST', 'PATCH'], true)) {
            $headers['X-HTTP-Method-Override'] = $method;
        }

        if ($contentType) {
            $headers['Content-Type'] = $contentType;
        }

        $defaultOptions = [
            'headers' => $headers,
            'timeout' => 60,
            'connect_timeout' => 60,
        ];
        $options = array_merge_recursive($defaultOptions, $options);

        for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
            try {
                return $client->request($method, $uri, $options);
            } catch (ConnectException $e) {
                Logger::instance()->error("Connection error ({$attempt}/{$retryAttempts}): " . $e->getMessage());
            } catch (RequestException $e) {
                $response = $e->getResponse();
                $statusCode = $response ? $response->getStatusCode() : 'N/A';
                Logger::instance()->error("Request error ({$attempt}/{$retryAttempts}): HTTP $statusCode - " . $e->getMessage());

                if ($response && $statusCode >= 400 && $statusCode < 500) {
                    return $response;
                }
            }

            if ($attempt < $retryAttempts) {
                sleep(5);
            }
        }

        Logger::instance()->critical("Request failed after {$retryAttempts} attempts: {$method} {$uri}");
        return null;
    }

    /**
     * @throws Exception
     */

    private function httpClient(
        string $method,
        string $url,
               $data = null,
        string $contentType = 'application/x-www-form-urlencoded',
        int    $retryAttempts = 3
    ): array
    {
        $client = new Client();
        $token = Config::$mainToken;

        $headers = [
            'x-auth-user-token' => $token,
            'Content-Type' => $contentType ?: 'application/x-www-form-urlencoded',
        ];

        $options = [
            'headers' => $headers,
            'timeout' => 60,
            'connect_timeout' => 50,
        ];

        // Обработка данных для разных методов
        if ($method === 'POST' || $method === 'PUT') {
            $options[$contentType === 'application/json' ? 'json' : 'form_params'] = $data;
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        } elseif ($method === 'GET_P' && !empty($data)) {
            $method = 'GET';
            $url .= '/' . $data;
        }

        for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
            try {
                $response = $client->request($method, $url, $options);
                return [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getBody()->getContents(),
                ];
            } catch (ConnectException $e) {
                Logger::instance()->error("Connection error ({$attempt}/{$retryAttempts}): " . $e->getMessage());
            } catch (RequestException $e) {
                $response = $e->getResponse();
                $statusCode = $response ? $response->getStatusCode() : 'N/A';
                $body = $response ? $response->getBody()->getContents() : 'No response body';
                Logger::instance()->error("Request error ({$attempt}/{$retryAttempts}): HTTP $statusCode - " . $e->getMessage());

                if ($statusCode >= 400 && $statusCode < 500) {
                    return ['status' => $statusCode, 'body' => $body];
                }
            } catch (GuzzleException $e) {
                Logger::instance()->critical("GuzzleException: " . $e->getMessage());
                return ['status' => false, 'body' => $e->getMessage()];
            }

            if ($attempt < $retryAttempts) {
                sleep(5);
            }
        }

        Logger::instance()->critical("Request failed after {$retryAttempts} attempts: {$method} {$url}");
        return ['status' => false, 'body' => 'Request failed after retries'];
    }


}
