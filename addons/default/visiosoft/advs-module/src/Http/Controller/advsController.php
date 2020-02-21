<?php namespace Visiosoft\AdvsModule\Http\Controller;

use Anomaly\SettingsModule\Setting\Contract\SettingRepositoryInterface;
use Anomaly\Streams\Platform\Http\Controller\PublicController;
use Anomaly\Streams\Platform\Model\Advs\AdvsAdvsEntryModel;
use Anomaly\Streams\Platform\Model\Advs\PurchasePurchaseEntryModel;
use Anomaly\Streams\Platform\Model\Complaints\ComplaintsComplainTypesEntryModel;
use Anomaly\Streams\Platform\Model\Options\OptionsAdvertisementEntryModel;
use Visiosoft\AdvsModule\Adv\Command\appendRequestURL;
use Visiosoft\AdvsModule\Adv\Event\showAdPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Visiosoft\LocationModule\City\CityRepository;
use function PMA\Util\get;
use Sunra\PhpSimple\HtmlDomParser;
use Visiosoft\AdvsModule\Adv\AdvModel;
use Visiosoft\AdvsModule\Adv\Event\ChangeStatusAd;
use Visiosoft\AdvsModule\Adv\Event\CreateAd;
use Visiosoft\AdvsModule\Adv\Event\EditAd;
use Visiosoft\AdvsModule\Adv\Event\priceChange;
use Visiosoft\AdvsModule\Adv\Event\UpdateAd;
use Visiosoft\AdvsModule\Adv\Event\viewAd;
use Visiosoft\AdvsModule\Adv\Form\AdvFormBuilder;
use Visiosoft\CatsModule\Category\CategoryModel;
use Visiosoft\CommentsModule\Comment\CommentModel;
use Visiosoft\DopingsModule\Doping\DopingModel;
use Visiosoft\LocationModule\City\CityModel;
use Visiosoft\LocationModule\Country\CountryModel;
use Visiosoft\AlgoliaModule\Search\SearchModel;
use Visiosoft\AlgoliatestModule\Http\Controller\Admin\IndexController;
use Visiosoft\CloudinaryModule\Video\VideoModel;
use Visiosoft\CustomfieldsModule\CustomField\CustomFieldModel;
use Anomaly\Streams\Platform\Addon\FieldType\FieldType;
use Visiosoft\FavsModule\Http\Controller\FavsController;
use Visiosoft\LocationModule\District\DistrictModel;
use Visiosoft\LocationModule\Neighborhood\NeighborhoodModel;
use Visiosoft\LocationModule\Village\VillageModel;
use Visiosoft\PackagesModule\Http\Controller\PackageFEController;
use Anomaly\SelectFieldType\SelectFieldType;
use Visiosoft\AdvsModule\Adv\Contract\AdvRepositoryInterface;
use Visiosoft\CatsModule\Category\Contract\CategoryRepositoryInterface;
use Visiosoft\LocationModule\Country\Contract\CountryRepositoryInterface;
use Anomaly\Streams\Platform\Message\MessageBag;
use Visiosoft\PackagesModule\Package\PackageModel;
use Visiosoft\ProfileModule\Profile\Contract\ProfileRepositoryInterface;
use Visiosoft\ProfileModule\Profile\ProfileModel;

use Anomaly\Streams\Platform\Model\Customfields\CustomfieldsCustomFieldsEntryModel;
use Anomaly\Streams\Platform\Model\Customfields\CustomfieldsCustomFieldAdvsEntryModel;

use Illuminate\Contracts\Events\Dispatcher;
use Visiosoft\QrcontactModule\Qr\QrModel;
use Visiosoft\StoreModule\Ad\AdModel;


class AdvsController extends PublicController
{
    private $adv_model;
    private $adv_repository;

    private $country_repository;

    private $city_model;
    private $cityRepository;

    private $district_model;

    private $neighborhood_model;

    private $village_model;

    private $profile_model;
    private $profile_repository;

    private $category_model;
    private $category_repository;

    private $requestHttp;
    private $settings_repository;
    private $event;

    public function __construct(
        AdvModel $advModel,
        AdvRepositoryInterface $advRepository,

        CountryRepositoryInterface $country_repository,

        CityModel $city_model,
        CityRepository $cityRepository,

        DistrictModel $district_model,

        NeighborhoodModel $neighborhood_model,

        VillageModel $village_model,

        ProfileModel $profile_model,
        ProfileRepositoryInterface $profile_repository,

        CategoryModel $categoryModel,
        CategoryRepositoryInterface $category_repository,

        SettingRepositoryInterface $settings_repository,

        Dispatcher $events,

        Request $request
    )
    {
        $this->adv_model = $advModel;
        $this->adv_repository = $advRepository;

        $this->country_repository = $country_repository;

        $this->city_model = $city_model;
        $this->cityRepository = $cityRepository;

        $this->district_model = $district_model;

        $this->neighborhood_model = $neighborhood_model;

        $this->village_model = $village_model;


        $this->profile_model = $profile_model;
        $this->profile_repository = $profile_repository;

        $this->category_model = $categoryModel;
        $this->category_repository = $category_repository;

        $this->settings_repository = $settings_repository;

        $this->event = $events;

        $this->requestHttp = $request;

        parent::__construct();
    }


    /**
     * @return \Illuminate\Contracts\View\View|mixed
     */
    public function index($category = null, $city = null)
    {
        $customParameters = array();
        $featured_advs = array();
        $subCats = array();

        $param = $this->requestHttp->toArray();

        $countries = $this->country_repository->viewAll();

        $isActiveDopings = $this->adv_model->is_enabled('dopings');

        // Search by category slug
        if ($category) {
            $categoryId = $this->category_repository->findBy('slug', $category);
            if ($categoryId) {
                $param['cat'] = $categoryId->id;
            }
        } elseif (isset($param['cat']) && !empty($param['cat'])) {
            $categoryId = $this->category_repository->find($param['cat']);
            return redirect($this->fullLink(
                $param,
                route('adv_list_seo', [$categoryId->slug]),
                array()
            ));
        }

        // Search by city slug
        if ($category) {
            if (is_null($city) && isset($param['city'][0]) && !empty($param['city'][0]) && strpos($param['city'][0], ',') === false) {
                $cityId = $this->cityRepository->find($param['city'][0]);
                return redirect($this->fullLink(
                    $param,
                    route('adv_list_seo', [$categoryId->slug, $cityId->slug]),
                    array()
                ));
            } elseif (isset($param['city']) && !empty($param['city'][0]) && strpos($param['city'][0], ',') === false) {
                $cityId = $this->cityRepository->find($param['city'][0]);
                if ($city !== $cityId->slug) {
                    return redirect($this->fullLink(
                        $param,
                        route('adv_list_seo', [$categoryId->slug, $cityId->slug]),
                        array()
                    ));
                }
            } elseif ($city && isset($param['city'][0]) && !empty($param['city'][0]) && strpos($param['city'][0], ',') !== false) {
                return redirect($this->fullLink(
                    $param,
                    route('adv_list_seo', [$categoryId->slug]),
                    array()
                ));
            } elseif ($city) {
                if (isset($param['city'][0]) && empty($param['city'][0])) {
                    return redirect($this->fullLink(
                        $param,
                        route('adv_list_seo', [$categoryId->slug]),
                        array()
                    ));
                } else {
                    $cityId = $this->cityRepository->findBy('slug', $city);
                    $param['city'] = [$cityId->id];
                }
            }
        }


        $isActiveCustomFields = $this->adv_model->is_enabled('customfields');
        $advs = $this->adv_repository->searchAdvs('list', $param, $customParameters);
        $advs = $this->adv_repository->addAttributes($advs);


        if ($isActiveDopings and $param != null) {
            $featured_advs = app('Visiosoft\DopingsModule\Http\Controller\DopingsController')->listFeatures($advs);
        }

        foreach ($advs as $index => $ad) {
            $advs[$index]->detail_url = $this->adv_model->getAdvDetailLinkByModel($ad, 'list');
            $advs[$index] = $this->adv_model->AddAdsDefaultCoverImage($ad);

            if ($isActiveCustomFields && isset($param['cat']) and $param['cat'] != "") {
                $rtnvalues = app('Visiosoft\CustomfieldsModule\Http\Controller\cfController')
                    ->indexseen($ad, $param['cat'], $advs, $index);
                $advs = $rtnvalues['advs'];
                $seenList = $rtnvalues['seenList'];
            }
        }


        if (isset($param['cat']) and $param['cat'] != "") {
            $cat = $param['cat'];
            $seo_keywords = $this->category_model->getMeta_keywords($param['cat']);
            $seo_description = $this->category_model->getMeta_description($param['cat']);
            $seo_title = $this->category_model->getMeta_title($param['cat']);

            $this->template->set('meta_keywords', implode(',', $seo_keywords));
            $this->template->set('meta_description', $seo_description);
            $this->template->set('meta_title', $seo_title);

            $mainCats = $this->category_model->getMains($cat);
            $current_cat = $this->category_model->getCat($cat);
            $mainCats[] = [
                'id' => $current_cat->id,
                'val' => $current_cat->name,
            ];
            $subCats = $this->category_repository->getSubCatById($cat);
        } else {
            $cat = null;
            $mainCats = $this->category_repository->mainCats();
        }

        if ($isActiveCustomFields) {
            $returnvalues = app('Visiosoft\CustomfieldsModule\Http\Controller\cfController')->index($mainCats, $subCats);
            $checkboxes = $returnvalues['checkboxes'];
            $topfields = $returnvalues['topfields'];
            $ranges = $returnvalues['ranges'];
            $radio = $returnvalues['radio'];
        }

        if (!empty($param['user'])) {
            $user = $this->profile_repository->getUser($param['user']);
            $userProfile = $this->profile_repository->getProfile($user->id);
        }

        Cookie::queue(Cookie::make('last_search', $this->requestHttp->getRequestUri(), 84000));

        $viewType = $this->requestHttp->cookie('viewType');

        $compact = compact('advs', 'countries', 'mainCats', 'subCats', 'checkboxes', 'request', 'param',
            'user', 'userProfile', 'featured_advs', 'viewType', 'topfields', 'ranges', 'seenList', 'searchedCountry', 'radio');

        return $this->viewTypeBasedRedirect($viewType, $compact);
    }

    public function fullLink($request, $url, $newParameters) {
        return $this->dispatch(new appendRequestURL($request, $url, $newParameters));
    }

    public function viewTypeBasedRedirect($viewType, $compact) {
        if (isset($viewType) and $viewType == 'table') {
            return $this->view->make('visiosoft.module.advs::list/table', $compact);
        } elseif (isset($viewType) and $viewType == 'map') {
            return $this->view->make('visiosoft.module.advs::list/map', $compact);
        } elseif (isset($viewType) and $viewType == 'gallery') {
            return $this->view->make('visiosoft.module.advs::list/gallery', $compact);
        } else {
            return $this->view->make('visiosoft.module.advs::list/list', $compact);
        }
    }

    public function viewType($type)
    {
        Cookie::queue(Cookie::make('viewType', $type, 84000));
        return redirect($this->request->headers->get('referer'));
    }

    public function view($seo, $id = null)
    {
        $id = is_null($id) ? $seo : $id;

        $categories = array();
        $categories_id = array();
        $isActiveComplaints = $this->adv_model->is_enabled('complaints');
        $isCommentActive = $this->adv_model->is_enabled('comments');

        if ($isActiveComplaints) {
            $complaints = ComplaintsComplainTypesEntryModel::all();
        }

        $adv = $this->adv_repository->getListItemAdv($id);

        $recommended_advs = $this->adv_repository->getRecommendedAds($adv->id);

        foreach ($recommended_advs as $index => $ad) {
            $recommended_advs[$index]->detail_url = $this->adv_model->getAdvDetailLinkByModel($ad, 'list');
            $recommended_advs[$index] = $this->adv_model->AddAdsDefaultCoverImage($ad);
        }

        for ($i = 1; $i < 7; $i++) {
            $cat = "cat" . $i;
            if ($adv->$cat != null) {
                $item = $this->category_repository->getItem($adv->$cat);
                if (!is_null($item)) {
                    $categories['cat' . $i] = [
                        'name' => $item->name,
                        'id' => $item->id
                    ];
                    $categories_id[] = $item->id;
                }

            }
        }

        if ($this->adv_model->is_enabled('customfields')) {
            $features = app('Visiosoft\CustomfieldsModule\Http\Controller\cfController')->view($adv);
        }

        //Cloudinary Module
        $adv->video_url = null;
        $isActiveCloudinary = $this->adv_model->is_enabled('cloudinary');
        if ($isActiveCloudinary) {

            $CloudinaryModel = new VideoModel();
            $Cloudinary = $CloudinaryModel->getVideo($id);

            if (count($Cloudinary->get()) > 0) {
                $adv->video_url = $Cloudinary->first()->toArray()['url'];
            }
        }

        $profile = $this->profile_model->getProfile($adv->created_by_id)->first();


        if ($isCommentActive) {
            $CommentModel = new CommentModel();
            $comments = $CommentModel->getComments($adv->id)->get();
        }
        $this->event->dispatch(new viewAd($adv));//view ad

        $this->template->set('meta_keywords', implode(',', explode(' ', $adv->name)));
        $this->template->set('meta_description', strip_tags($adv->advs_desc, ''));
        $this->template->set('meta_title', $adv->name . "|" . end($categories)['name']);


        if ($adv->created_by_id == isset(auth()->user()->id) OR $adv->status == "approved") {
            return $this->view->make('visiosoft.module.advs::ad-detail/detail', compact('adv', 'complaints', 'recommended_advs', 'categories', 'features', 'profile', 'comments', 'qrSRC'));
        } else {
            return back();
        }

    }

    public function getLocations()
    {
        $table = $this->requestHttp->table;
        $id = $this->requestHttp->id;
        $db = $this->requestHttp->typeDb;

        $location = "";
        if ($table == "cities") {
            $location = $this->city_model->query()->where($db, $id)->get();
        } elseif ($table == "districts") {
            $location = $this->district_model->query()->whereIn($db, $id)->get();
        } elseif ($table == "neighborhoods") {
            $location = $this->neighborhood_model->query()->where($db, $id)->get();
        } elseif ($table == "village") {
            $location = $this->village_model->query()->where($db, $id)->get();
        }

        return $location;
    }

    public function deleteAd(AdvRepositoryInterface $advs, $id)
    {
        $ad = $this->adv_model->find($id);
        if (!Auth::user()) {
            redirect('/login?redirect=' . url()->current())->send();
        }

        if ($ad->created_by_id != Auth::id()) {
            $this->messages->error(trans('visiosoft.module.advs::message.delete_author_error'));
        }

        $advs->softDeleteAdv($id);
        $this->messages->error(trans('visiosoft.module.advs::message.success_delete'));
        return back();
    }

    public function getCats($id)
    {
        return $this->category_repository->getSubCatById($id);
    }

    public function getCatsForNewAd($id)
    {
        $cats = $this->getCats($id);
        $count_user_ads = count($this->adv_model->userAdv()->get());

        if (empty($cats->toArray())) {

            $cats = trans('visiosoft.module.advs::message.create_ad_with_post_cat');

            if (setting_value('visiosoft.module.advs::default_adv_limit') <= $count_user_ads) {
                if ($this->adv_model->is_enabled('packages')) {
                    $packageModel = new PackageModel();
                    $parent_cat = $this->category_model->getParentCats($id, 'parent_id');
                    $package = $packageModel->reduceLimit($parent_cat);
                    if ($package != null) {
                        return $package;
                    }
                } else {
                    $msg = trans('visiosoft.module.advs::message.max_ad_limit');
                    return $msg;
                }
            }


        }
        return $cats;
    }

    public function create(Request $request, AdvFormBuilder $formBuilder, CategoryRepositoryInterface $repository)
    {
        if (!Auth::user()) {
            redirect('/login?redirect=' . url()->current())->send();
        }

        $isActive = new AdvModel();
        $cats = $request->toArray();
        unset($cats['_token']);

        $end = count($cats);
        $cats_d = array();
        $categories = array_keys($cats);


        for ($i = 0; $i < $end; $i++) {
            $plus1 = $i + 1;

            $cat = $repository->getSingleCat($cats['cat' . $plus1]);
            $cats_d['cat' . $plus1] = $cat->name;
        }
        if ($isActive->is_enabled('customfields')) {
            $custom_fields = app('Visiosoft\CustomfieldsModule\Http\Controller\cfController')->create($categories);
        }
        //Cloudinary Module
        return $this->view->make('visiosoft.module.advs::new-ad/new-create', compact(
            'request', 'formBuilder', 'cats_d', 'custom_fields'));
    }

    public function store
    (
        AdvFormBuilder $form,
        MessageBag $messages,
        Request $request,
        SettingRepositoryInterface $settings,
        AdvRepositoryInterface $advRepository,
        CategoryRepositoryInterface $categoryRepository,
        Dispatcher $events,
        AdvModel $advModel,
        CategoryModel $categoryModel
    )
    {
        if (!Auth::user()) {
            redirect('/login?redirect=' . url()->current())->send();
        }
        $messages->pull('error');
        $isActiveDopings = $advModel->is_enabled('dopings');
        if ($request->action == "update") {
            $error = $form->build($request->update_id)->validate()->getFormErrors()->getMessages();
            if (!empty($error)) {
                return $this->redirect->back();
            }
            /*  Update Adv  */
            $adv = AdvsAdvsEntryModel::find($request->update_id);

            $count_user_ads = count($this->adv_model->userAdv()->get());

            if (setting_value('visiosoft.module.advs::default_adv_limit') < $count_user_ads) {
                if ($advModel->is_enabled('packages') and $adv->slug == "") {
                    $parent_cat = $categoryModel->getParentCats($request->cat1, 'parent_id');
                    $packageModel = new PackageModel();
                    $package = $packageModel->reduceLimit($parent_cat, 'reduce');
                    if ($package != null)
                        $this->messages->error(trans('visiosoft.module.advs::message.please_buy_package'));

                } else
                    $this->messages->error(trans('visiosoft.module.advs::message.max_ad_limit.title'));

                return redirect('/');
            }

            $adv->is_get_adv = $request->is_get_adv;
            $adv->save();

            //algolia Search Module
            $isActiveAlgolia = $advModel->is_enabled('algolia');
            if ($isActiveAlgolia) {
                $algolia = new SearchModel();
                if ($adv->slug == "") {
                    $algolia->saveAlgolia($adv->toArray(), $settings);
                } else {
                    $algolia->updateAlgolia($request->toArray(), $settings);
                }
            }
            //Cloudinary Module
            $isActiveCloudinary = $advModel->is_enabled('cloudinary');
            if ($isActiveCloudinary) {

                $CloudinaryModel = new VideoModel();
                $CloudinaryModel->updateRequest($request);

                if ($request->url != "") {
                    $adv->cover_photo = "https://res.cloudinary.com/" . $request->cloudName . "/video/upload/w_400,e_loop/" .
                        $request->uploadKey . "/" . $request->filename . "gif";
                    $adv->save();
                }
            }
            if ($this->adv_model->is_enabled('customfields')) {
                app('Visiosoft\CustomfieldsModule\Http\Controller\cfController')->store($adv, $request);
            }


            $form->render($request->update_id);
            $post = $form->getPostData();
            $post['id'] = $request->update_id;
            $events->dispatch(new priceChange($post));//price history
            if ($request->url == "") {
                $LastAdv = $advModel->getLastUserAdv();
                $advRepository->cover_image_update($LastAdv);
            }

            if ($form->hasFormErrors()) {
                $cats = $request->toArray();

                $cats_d = array();

                foreach ($cats as $para => $value) {
                    if (substr($para, 0, 3) === "cat") {
                        $id = $cats[$para];
                        $cat = $categoryRepository->getSingleCat($id);
                        if ($cat != null) {
                            $cats_d[$para] = $cat->name;
                        }
                    }
                }
                return redirect('/advs/edit_advs/' . $request->update_id)->with('cats_d', $cats_d)->with('request', $request);
            }

            $foreign_currencies = new AdvModel();
            $isUpdate = $request->update_id;
            $foreign_currencies->foreignCurrency($request->currency, $request->price, $request->currencies, $isUpdate, $settings);

            if ($adv->slug == "") {
                $events->dispatch(new CreateAd($request->update_id, $settings));//Create Notify
            } else {
                $events->dispatch(new EditAd($request->update_id, $settings, $adv));//Update Notify
            }


            if ($isActiveDopings) {
                return redirect(route('add_doping', [$request->update_id]));
            } else {
                return redirect('/advs/adv/' . $request->update_id);
            }
        }

        /* New Create Adv */
        $request->publish_at = date('Y-m-d H:i:s');
        $all = $request->all();
        $new = AdvModel::query()->create($all);
        return redirect('/advs/edit_advs/' . $new->id);
    }

    public function edit
    (
        $id,
        AdvFormBuilder $advFormBuilder,
        AdvRepositoryInterface $advRepository,
        CategoryRepositoryInterface $categoryRepository,
        AdvModel $advModel
    )
    {
        if (!Auth::user()) {
            redirect('/login?redirect=' . url()->current())->send();
        }
        $isActive = new AdvModel();
        $adv = $advRepository->getAdvArray($id);


        if ($adv['created_by_id'] != Auth::id() && !Auth::user()->hasRole('admin')) {
            abort(403);
        }
        $cats_d = array();
        $cat = 'cat';
        $cats = array();

        for ($i = 1; $i < 7; $i++) {
            if ($adv[$cat . $i] != null) {
                $name = $categoryRepository->getSingleCat($adv[$cat . $i]);
                if (!is_null($name)) {
                    $cats_d['cat' . $i] = $name->name;
                    $cats['cat' . $i] = $name->id;
                } else {
                    $this->messages->info(trans('visiosoft.module.advs::message.update_category_info'));
                }

            }
        }

        //Cloudinary Module
        $isActiveCloudinary = new AdvModel();
        $isActiveCloudinary = $isActiveCloudinary->is_enabled('cloudinary');
        if ($isActiveCloudinary) {
            $CloudinaryModel = new VideoModel();
            $Cloudinary = $CloudinaryModel->getVideo($id)->get();

            if (count($Cloudinary) > 0) {
                $Cloudinary = $Cloudinary->first()->toArray();
            }

        }

        $request = $cats;

        $categories = array_keys($cats);

        if ($isActive->is_enabled('customfields')) {
            $custom_fields = app('Visiosoft\CustomfieldsModule\Http\Controller\cfController')->edit($adv, $categories, $cats);
        }

        return $this->view->make('visiosoft.module.advs::new-ad/new-create', compact('id', 'cats_d', 'request', 'Cloudinary', 'cities', 'adv', 'custom_fields'));
    }

    public function statusAds($id, $type, SettingRepositoryInterface $settings, Dispatcher $events)
    {
        $ad = $this->adv_model->getAdv($id);
        $auto_approved = $settings->value('visiosoft.module.advs::auto_approve');
        $default_published_time = $settings->value('visiosoft.module.advs::default_published_time');

        if ($auto_approved == true AND $type == 'pending_admin') {
            $type = "approved";
        }
        if ($type == "approved" and $auto_approved != true) {
            $type = "pending_admin";
        }

        if ($type == "approved") {
            $this->adv_model->publish_at_Ads($id);
            if ($ad->finish_at == NULL AND $type == "approved") {
                if ($this->adv_model->is_enabled('packages')) {
                    $packageModel = new PackageModel();
                    $published_time = $packageModel->reduceTimeLimit($ad->cat1);
                    if ($published_time != null) {
                        $default_published_time = $published_time;
                    }
                }
                $this->adv_model->finish_at_Ads($id, $default_published_time);
            }
        }

        $isActiveAlgolia = $this->adv_model->is_enabled('algolia');
        if ($isActiveAlgolia) {
            $algolia = new SearchModel();
            $algolia->updateStatus($id, $type, $settings);
        }

        $this->adv_model->statusAds($id, $type);
        $events->dispatch(new ChangeStatusAd($id, $settings));//Create Notify
        $this->messages->success(trans('streams::message.edit_success', ['name' => 'Status']));
        return back();
    }

    public function cats()
    {
        if (!Auth::user()) {
            redirect('/login?redirect=' . url()->current())->send();
        }
        $main_cats = $this->category_repository->mainCats();

        return $this->view->make('visiosoft.module.advs::new-ad/post-cat', compact('main_cats'));

    }

    /**
     * @param $id
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|mixed
     */
    public function editCategoryForAd($id)
    {
        if (!Auth::user()) {
            redirect('/login?redirect=' . url()->current())->send();
        }

        $adv = $this->adv_model->userAdv(true)->find($id);

        if (is_null($adv)) {
            abort(403);
        }

        if ($this->requestHttp->action == 'update') {
            $params = $this->requestHttp->all();
            unset($params['action']);

            for ($i = 2; $i <= 7; $i++) {
                if (!isset($params['cat' . $i])) {
                    $params['cat' . $i] = NULL;
                }
            }

            $adv->update($params);
            $this->messages->success(trans('visiosoft.module.advs::message.updated_category_msg'));
            return redirect('/advs/edit_advs/' . $id);
        }

        $categories = $this->adv_repository->getCategoriesWithAdID($id);

        return $this->view->make('visiosoft.module.advs::new-ad/edit-cat', compact('id', 'adv', 'categories'));

    }

    public function login()
    {
        if (auth()->check()) {
            return $this->redirect->to($this->request->get('redirect', '/'));
        }

        $urlPrev = str_replace(url('/'), '', url()->previous());

        return $this->view->make('theme::login', compact('urlPrev'));
    }

    public function register()
    {

        if (auth()->check()) {
            return redirect('/');
        }

        return $this->view->make('theme::register');
    }

    public function passwordForgot()
    {
        if (auth()->check()) {
            return redirect('/');
        }

        return $this->view->make('theme::passwords/forgot');
    }

    public function passwordReset(Request $request)
    {
        if (auth()->check()) {
            return redirect('/');
        }
        $code = $request->email;
        return $this->view->make('theme::passwords/reset', compact('code'));
    }

    public function homePage(CategoryRepositoryInterface $repository)
    {
        $cats = $repository->mainCats();

        return $this->view->make('theme::addons/anomaly/pages-module/page', compact('cats'));
    }

    public function map(AdvRepositoryInterface $advRepository,
                        CategoryRepositoryInterface $categories,
                        CountryRepositoryInterface $countries,
                        ProfileRepositoryInterface $profileRepository,
                        Request $request)
    {

        return $this->index($advRepository, $categories, $countries, $profileRepository, $request, true);

    }

    public function mapJson(Request $request, AdvRepositoryInterface $repository)
    {
        $param = $request->toArray();
        $customParameters = array();
        $advModel = new AdvModel();

        $advs = $repository->searchAdvs('map', $param, $customParameters);
        foreach ($advs as $index => $ad) {
            $advs[$index]->seo_link = $advModel->getAdvDetailLinkByModel($ad, 'list');
            $advs[$index] = $advModel->AddAdsDefaultCoverImage($ad);
        }
        return response()->json($advs);
    }

    public function getAdvsByProfile(AdvRepositoryInterface $advRepository, Request $request)
    {
        $my_advs = new AdvModel();
        $type = $request->type;
        if ($type == 'pending') {
            $page_title = trans('visiosoft.module.advs::field.pending_adv.name');
            $my_advs = $my_advs->pendingAdvsByUser();

        } else if ($type == 'archived') {
            $page_title = trans('visiosoft.module.advs::field.archived_adv.name');
            $my_advs = $my_advs->archivedAdvsByUser();

        } else if ($type == 'favs') {
            //Get Favorites Advs
            $isActiveFavs = new AdvModel();
            $isActiveFavs = $isActiveFavs->is_enabled('favs');

            if ($isActiveFavs) {

                $page_title = trans('visiosoft.module.advs::field.favs_adv.name');
                $favs = new FavsController();
                $favs = $favs->getFavsByProfile();

                $fav_ids = array();
                foreach ($favs as $fav) {
                    $fav_ids[] = $fav['adv_name_id'];//fav advs id List
                }
                $my_advs = $my_advs->favsAdvsByUser($fav_ids);
            }
        } else {
            $page_title = trans('visiosoft.module.advs::field.my_adv.name');
            $my_advs = $my_advs->myAdvsByUser();

        }
        $my_advs = $my_advs->orderByDesc('id');
        $my_advs = $advRepository->addAttributes($my_advs->get());
        $files = array();
        foreach ($my_advs as $my_adv) {
            $files[] = $my_adv->files;
        }
        return response()->json(['success' => true, 'content' => $my_advs, 'files' => $files, 'title' => $page_title]);
    }

    public function authCheck()
    {
        if (auth()->check()) {
            return auth()->user();
        }

        return "false";
    }

    public function isActive($slug)
    {
        $query = new AdvModel();

        return $query->is_enabled($slug);
    }

    public function isActiveJson($slug)
    {
        $isActive = $this->isActive($slug);
        return response()->json(array('isActive' => $isActive));
    }

    public function checkParentCat($id)
    {
        $option = new CategoryModel();
        return $option->getParentCats($id);
    }

    public function checkUser()
    {
        if (Auth::check()) {
            return response()->json([
                'success' => true,
            ]);
        } else {
            return response()->json([
                'success' => false,
            ]);
        }
    }

    public function advAddCart($id, $quantity = 1)
    {
        $thisModel = new AdvModel();
        $adv = $thisModel->isAdv($id);
        $response = array();
        if ($adv) {
            $cart = $thisModel->addCart($adv, $quantity);
            $response['status'] = "success";
        } else {
            $response['status'] = "error";
            $response['msg'] = trans('visiosoft.module.advs::message.error_added_cart');
        }
        return back();
    }

    public function addCart(Request $request)
    {
        $id = $request->id;
        $quantity = $request->quantity;
        $thisModel = new AdvModel();
        $adv = $thisModel->isAdv($id);
        $response = array();
        if ($adv) {
            $cart = $thisModel->addCart($adv, $quantity);
            $response['status'] = "success";
        } else {
            $response['status'] = "error";
            $response['msg'] = trans('visiosoft.module.advs::message.error_added_cart');
        }
        return $response;
    }

    public function stockControl(Request $request, AdvRepositoryInterface $advRepository)
    {
        $quantity = $request->quantity;
        $id = $request->id;
        $type = $request->type;
        $advmodel = new AdvModel();
        $adv = $advmodel->getAdv($id);

        $status = $advmodel->stockControl($id, $quantity);

        $response = array();
        if ($status == 1) {
            $response['newQuantity'] = $advRepository->getQuantity($quantity, $type, $adv);

        } else {
            $response['newQuantity'] = $adv->stock;
        }

        $response['newPrice'] = $adv->price * $response['newQuantity'];

        $separator = ",";
        $decimals = 2;
        $point = ".";

        $response['newPrice'] = number_format($response['newPrice'], $decimals, $point, str_replace('&#160;', ' ', $separator));
        $symbol = config('streams::currencies.supported.' . strtoupper($adv->currency) . '.symbol');

        $response['newPrice'] = $symbol . $response['newPrice'];
        $response['status'] = $status;
        $response['maxQuantity'] = $adv->stock;
        return $response;
    }

    public function showPhoneCounter(Request $request, AdvModel $advModel, Dispatcher $events)
    {
        $ad_id = $request->id;
        $ad = $advModel->getAdv($ad_id);

        if ($advModel->is_enabled('phoneclickcounter')) {
            $events->dispatch(new showAdPhone($ad));//show ad phone events
        }
        return "success";
    }

}
