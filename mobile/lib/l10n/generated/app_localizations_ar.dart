// ignore: unused_import
import 'package:intl/intl.dart' as intl;

import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Arabic (`ar`).
class AppLocalizationsAr extends AppLocalizations {
  AppLocalizationsAr([String locale = 'ar']) : super(locale);

  @override
  String get appName => 'البحر';

  @override
  String get appFullName => 'البحر للمنزل والمعيشة';

  @override
  String get appTagline => 'قطعٌ مختارة بعناية لمنزلٍ أجمل';

  @override
  String get retry => 'إعادة المحاولة';

  @override
  String get cancel => 'إلغاء';

  @override
  String get confirm => 'تأكيد';

  @override
  String get save => 'حفظ';

  @override
  String get delete => 'حذف';

  @override
  String get edit => 'تعديل';

  @override
  String get close => 'إغلاق';

  @override
  String get done => 'تم';

  @override
  String get next => 'التالي';

  @override
  String get skip => 'تخطٍّ';

  @override
  String get continueLabel => 'متابعة';

  @override
  String get apply => 'تطبيق';

  @override
  String get reset => 'إعادة ضبط';

  @override
  String get clear => 'مسح';

  @override
  String get seeAll => 'عرض الكل';

  @override
  String get loading => 'جارٍ التحميل…';

  @override
  String get ok => 'حسنًا';

  @override
  String get share => 'مشاركة';

  @override
  String get copy => 'نسخ';

  @override
  String get copied => 'تم النسخ';

  @override
  String get search => 'بحث';

  @override
  String get optional => 'اختياري';

  @override
  String get fieldRequired => 'هذا الحقل مطلوب';

  @override
  String get add => 'إضافة';

  @override
  String get remove => 'إزالة';

  @override
  String get change => 'تغيير';

  @override
  String get call => 'اتصال';

  @override
  String get directions => 'الاتجاهات';

  @override
  String get signIn => 'تسجيل الدخول';

  @override
  String get signOut => 'تسجيل الخروج';

  @override
  String get signInRequiredTitle => 'سجّل دخولك للمتابعة';

  @override
  String get signInRequiredMessage => 'تُحفظ سلّتك وطلباتك وقائمة أمنياتك في حسابك.';

  @override
  String get back => 'رجوع';

  @override
  String get menu => 'خيارات إضافية';

  @override
  String get errorTitle => 'حدث خطأ ما';

  @override
  String get errorGeneric => 'تعذّر إتمام العملية. يُرجى المحاولة مرة أخرى.';

  @override
  String get errorOffline => 'يبدو أنك غير متصل بالإنترنت. تحقّق من الاتصال وحاول مجددًا.';

  @override
  String get errorTimeout => 'استغرق الاتصال وقتًا أطول من المعتاد. حاول مجددًا.';

  @override
  String get errorServer => 'نواجه عطلًا مؤقتًا في الخدمة. يُرجى المحاولة بعد قليل.';

  @override
  String get errorNotFound => 'لم نعثر على ما تبحث عنه.';

  @override
  String get errorForbidden => 'لا تملك صلاحية الوصول إلى هذا المحتوى.';

  @override
  String errorRateLimited(int seconds) {
    return 'محاولات كثيرة. يُرجى الانتظار $seconds ثانية.';
  }

  @override
  String get errorSessionExpired => 'انتهت جلستك. يُرجى تسجيل الدخول مجددًا.';

  @override
  String get offlineBanner => 'غير متصل — نعرض المتاح حاليًا';

  @override
  String get notFoundTitle => 'غير موجود';

  @override
  String requestReference(String id) {
    return 'رقم المرجع: $id';
  }

  @override
  String get navHome => 'الرئيسية';

  @override
  String get navCategories => 'تسوّق';

  @override
  String get navAuctions => 'المزادات';

  @override
  String get navCart => 'السلة';

  @override
  String get navAccount => 'حسابي';

  @override
  String get onboardingTitle1 => 'عيشٌ بجمالٍ مدروس';

  @override
  String get onboardingBody1 => 'أثاث وديكور ومستلزمات مختارة لمنازل قطر.';

  @override
  String get onboardingTitle2 => 'فرعك المفضّل وأسعاره';

  @override
  String get onboardingBody2 => 'اطّلع على المخزون والأسعار في أقرب صالة عرض إليك، ثم اختر التوصيل أو الاستلام.';

  @override
  String get onboardingTitle3 => 'زايِد على قطعٍ استثنائية';

  @override
  String get onboardingBody3 => 'شارك في المزادات المباشرة واقتنِ قطعًا فريدة بمزايدة لحظية.';

  @override
  String get getStarted => 'ابدأ الآن';

  @override
  String get chooseLanguageTitle => 'اختر لغتك';

  @override
  String get chooseLanguageSubtitle => 'يمكنك تغييرها في أي وقت من الإعدادات.';

  @override
  String get languageArabic => 'العربية';

  @override
  String get languageEnglish => 'English';

  @override
  String get loginTitle => 'أهلًا بك';

  @override
  String get loginSubtitle => 'أدخل رقم جوالك القطري وسنرسل إليك رمز التحقق برسالة نصية.';

  @override
  String get phoneLabel => 'رقم الجوال';

  @override
  String get phoneHint => '5512 3456';

  @override
  String get phoneInvalid => 'يُرجى إدخال رقم جوال قطري صحيح.';

  @override
  String get sendCode => 'إرسال الرمز';

  @override
  String get otpTitle => 'تأكيد رقم الجوال';

  @override
  String otpSubtitle(String phone) {
    return 'أدخل الرمز المكوّن من 6 أرقام المُرسل إلى $phone';
  }

  @override
  String get otpCodeLabel => 'رمز التحقق';

  @override
  String get verify => 'تحقّق';

  @override
  String get resendCode => 'إعادة إرسال الرمز';

  @override
  String resendIn(int seconds) {
    return 'إعادة الإرسال بعد $seconds ث';
  }

  @override
  String get changeNumber => 'تغيير الرقم';

  @override
  String get codeSent => 'تم إرسال رمز جديد.';

  @override
  String get registerTitle => 'أنشئ حسابك';

  @override
  String get registerSubtitle => 'بعض التفاصيل البسيطة لتجربة مصمّمة لك.';

  @override
  String get nameLabel => 'الاسم الكامل';

  @override
  String get emailLabel => 'البريد الإلكتروني';

  @override
  String get passwordLabel => 'كلمة المرور';

  @override
  String get passwordConfirmLabel => 'تأكيد كلمة المرور';

  @override
  String get passwordOptionalHint => 'اختياري — يتيح لك الدخول دون رمز تحقق';

  @override
  String get referralCodeLabel => 'رمز الإحالة';

  @override
  String get marketingConsent => 'أرسلوا لي العروض والتشكيلات الجديدة ودعوات المزادات';

  @override
  String get createAccount => 'إنشاء الحساب';

  @override
  String get loginWithPassword => 'الدخول بكلمة المرور';

  @override
  String get loginWithOtp => 'الدخول برمز التحقق';

  @override
  String get emailOrPhoneLabel => 'رقم الجوال أو البريد الإلكتروني';

  @override
  String get forgotPassword => 'نسيت كلمة المرور؟';

  @override
  String get resetPasswordTitle => 'إعادة تعيين كلمة المرور';

  @override
  String get resetPasswordSubtitle => 'سنرسل رمزًا إلى جوالك للتأكد من هويتك.';

  @override
  String get newPasswordLabel => 'كلمة المرور الجديدة';

  @override
  String get resetPassword => 'تعيين كلمة المرور';

  @override
  String get passwordResetSuccess => 'تم تحديث كلمة المرور. يُرجى تسجيل الدخول.';

  @override
  String get passwordsDoNotMatch => 'كلمتا المرور غير متطابقتين.';

  @override
  String get passwordTooShort => 'استخدم 8 أحرف على الأقل.';

  @override
  String get termsAgreementPrefix => 'بالمتابعة فإنك توافق على';

  @override
  String get termsAndPrivacy => 'الشروط وسياسة الخصوصية';

  @override
  String get continueAsGuest => 'تصفّح كضيف';

  @override
  String welcomeBack(String name) {
    return 'أهلًا بعودتك يا $name';
  }

  @override
  String get emailInvalid => 'يُرجى إدخال بريد إلكتروني صحيح.';

  @override
  String debugCodeHint(String code) {
    return 'رمز التطوير: $code';
  }

  @override
  String get sendResetCode => 'إرسال رمز الاستعادة';

  @override
  String get homeGreeting => 'سعداء برؤيتك';

  @override
  String homeGreetingNamed(String name) {
    return 'مرحبًا $name';
  }

  @override
  String get searchHint => 'ابحث عن منظفات، مناديل، تنظيف…';

  @override
  String get homeEmpty => 'نُجهّز تشكيلتنا الجديدة. عُد إلينا قريبًا.';

  @override
  String endsIn(String time) {
    return 'ينتهي خلال $time';
  }

  @override
  String startsIn(String time) {
    return 'يبدأ خلال $time';
  }

  @override
  String get preferredBranch => 'الفرع المفضّل';

  @override
  String get chooseBranch => 'اختر فرعًا';

  @override
  String shoppingAt(String branch) {
    return 'تتسوّق من $branch';
  }

  @override
  String get shopNow => 'تسوّق الآن';

  @override
  String countdownDays(int days) {
    return '$days ي';
  }

  @override
  String get categoriesTitle => 'تسوّق حسب الفئة';

  @override
  String allInCategory(String category) {
    return 'كل $category';
  }

  @override
  String get productsTitle => 'المنتجات';

  @override
  String get filters => 'التصفية';

  @override
  String get sortBy => 'ترتيب حسب';

  @override
  String get sortRelevance => 'الأكثر صلة';

  @override
  String get sortPriceLowHigh => 'السعر: من الأقل إلى الأعلى';

  @override
  String get sortPriceHighLow => 'السعر: من الأعلى إلى الأقل';

  @override
  String get sortNewest => 'الأحدث';

  @override
  String get sortRating => 'الأعلى تقييمًا';

  @override
  String get sortPopularity => 'الأكثر رواجًا';

  @override
  String get priceRange => 'السعر (ر.ق)';

  @override
  String get minPrice => 'من';

  @override
  String get maxPrice => 'إلى';

  @override
  String get inStockOnly => 'المتوفر فقط';

  @override
  String get onSaleOnly => 'العروض فقط';

  @override
  String get brandFilter => 'العلامة التجارية';

  @override
  String get showResults => 'عرض النتائج';

  @override
  String get clearAll => 'مسح الكل';

  @override
  String get noProducts => 'لا توجد منتجات بعد';

  @override
  String get noProductsMessage => 'جرّب تعديل خيارات التصفية أو استكشاف فئة أخرى.';

  @override
  String resultsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count نتيجة',
      many: '$count نتيجة',
      few: '$count نتائج',
      two: 'نتيجتان',
      one: 'نتيجة واحدة',
      zero: 'لا توجد نتائج',
    );
    return '$_temp0';
  }

  @override
  String get searchTitle => 'البحث';

  @override
  String get recentSearches => 'عمليات البحث الأخيرة';

  @override
  String get clearRecent => 'مسح';

  @override
  String get searchPrompt => 'ابحث بالعربية أو الإنجليزية، بالاسم أو رمز المنتج أو الباركود.';

  @override
  String searchNoResults(String query) {
    return 'لا توجد نتائج لـ «$query»';
  }

  @override
  String get badgeNew => 'جديد';

  @override
  String get badgeSale => 'تخفيض';

  @override
  String get badgeBestseller => 'الأكثر مبيعًا';

  @override
  String get outOfStock => 'غير متوفر';

  @override
  String get inStock => 'متوفر';

  @override
  String onlyLeft(int count) {
    return 'تبقّى $count فقط';
  }

  @override
  String discountOff(int percent) {
    return '-$percent٪';
  }

  @override
  String get subcategories => 'تصفّح';

  @override
  String get addToCart => 'أضف إلى السلة';

  @override
  String get addedToCart => 'تمت الإضافة إلى سلتك';

  @override
  String get viewCart => 'عرض السلة';

  @override
  String selectOption(String attribute) {
    return 'اختر $attribute';
  }

  @override
  String get specifications => 'المواصفات';

  @override
  String get dimensions => 'الأبعاد';

  @override
  String get dimensionLength => 'الطول';

  @override
  String get dimensionWidth => 'العرض';

  @override
  String get dimensionHeight => 'الارتفاع';

  @override
  String get weight => 'الوزن';

  @override
  String get warranty => 'الضمان';

  @override
  String warrantyMonths(int months) {
    String _temp0 = intl.Intl.pluralLogic(
      months,
      locale: localeName,
      other: '$months شهر',
      many: '$months شهرًا',
      few: '$months أشهر',
      two: 'شهران',
      one: 'شهر واحد',
    );
    return '$_temp0';
  }

  @override
  String warrantyBy(String provider) {
    return 'مقدَّم من $provider';
  }

  @override
  String get assemblyRequired => 'يتطلب التركيب';

  @override
  String get assemblyAvailable => 'خدمة التركيب متاحة';

  @override
  String get oversizedItem => 'قطعة كبيرة — توصيل خاص';

  @override
  String get description => 'الوصف';

  @override
  String get details => 'التفاصيل';

  @override
  String get reviews => 'التقييمات';

  @override
  String reviewsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count تقييم',
      many: '$count تقييمًا',
      few: '$count تقييمات',
      two: 'تقييمان',
      one: 'تقييم واحد',
      zero: 'لا توجد تقييمات',
    );
    return '$_temp0';
  }

  @override
  String get writeReview => 'اكتب تقييمًا';

  @override
  String get noReviews => 'كن أول من يشارك رأيه.';

  @override
  String get relatedProducts => 'قد يعجبك أيضًا';

  @override
  String get availability => 'التوفّر';

  @override
  String availableAt(String branch) {
    return 'متوفر في $branch';
  }

  @override
  String notInBranch(String branch) {
    return 'غير متاح في $branch';
  }

  @override
  String outOfStockAt(String branch) {
    return 'نفد من $branch';
  }

  @override
  String get checkOtherBranches => 'تحقّق من الفروع الأخرى';

  @override
  String get branchAvailabilityTitle => 'المخزون حسب الفرع';

  @override
  String availableQty(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count+ قطعة متوفرة',
      many: '$count+ قطعة متوفرة',
      few: '$count قطع متوفرة',
      two: 'قطعتان متوفرتان',
      one: 'قطعة واحدة متوفرة',
      zero: 'غير متوفر',
    );
    return '$_temp0';
  }

  @override
  String get deliveryAvailable => 'توصيل للمنزل';

  @override
  String get pickupAvailable => 'استلام من الفرع';

  @override
  String get notifyBackInStock => 'نبّهني عند التوفّر';

  @override
  String get alertCreated => 'سنُعلمك فور توفّره.';

  @override
  String get addToWishlist => 'أضف إلى المفضلة';

  @override
  String get removeFromWishlist => 'إزالة من المفضلة';

  @override
  String saleEnds(String date) {
    return 'ينتهي العرض $date';
  }

  @override
  String skuLabel(String sku) {
    return 'رمز المنتج $sku';
  }

  @override
  String get quantity => 'الكمية';

  @override
  String get increaseQuantity => 'زيادة الكمية';

  @override
  String get decreaseQuantity => 'إنقاص الكمية';

  @override
  String ratingLabel(String rating) {
    return 'التقييم $rating من 5';
  }

  @override
  String get verifiedPurchase => 'مشترٍ موثّق';

  @override
  String get reportReview => 'إبلاغ';

  @override
  String get reviewReported => 'شكرًا لك، سيراجعه فريقنا.';

  @override
  String get productUnavailable => 'غير متاح حاليًا';

  @override
  String get selectBranchToBuy => 'اختر فرعًا لعرض السعر والمخزون';

  @override
  String imageOf(int index, int total) {
    return 'الصورة $index من $total';
  }

  @override
  String get questions => 'الأسئلة والأجوبة';

  @override
  String get askQuestion => 'اطرح سؤالًا';

  @override
  String get questionSubmitted => 'تم إرسال سؤالك.';

  @override
  String get noQuestions => 'لا توجد أسئلة بعد.';

  @override
  String get branchesTitle => 'صالات العرض';

  @override
  String get nearbyBranches => 'القريبة';

  @override
  String get allBranches => 'الكل';

  @override
  String get useMyLocation => 'استخدم موقعي';

  @override
  String get locationDenied => 'خدمة الموقع غير مفعّلة';

  @override
  String get locationDeniedMessage => 'اسمح بالوصول إلى موقعك لإيجاد أقرب صالات العرض، أو اختر من القائمة.';

  @override
  String get openSettings => 'فتح الإعدادات';

  @override
  String get mapView => 'الخريطة';

  @override
  String get listView => 'القائمة';

  @override
  String get openNow => 'مفتوح الآن';

  @override
  String get closedNow => 'مغلق';

  @override
  String closesAt(String time) {
    return 'يُغلق $time';
  }

  @override
  String opensAt(String time) {
    return 'يفتح $time';
  }

  @override
  String distanceKm(String km) {
    return '$km كم';
  }

  @override
  String get selectThisBranch => 'تسوّق من هذا الفرع';

  @override
  String get selectedBranch => 'فرعك';

  @override
  String get branchHours => 'ساعات العمل';

  @override
  String get holidays => 'العطلات والمواعيد الخاصة';

  @override
  String get closed => 'مغلق';

  @override
  String breakTime(String from, String to) {
    return 'استراحة $from–$to';
  }

  @override
  String get branchServices => 'الخدمات';

  @override
  String get serviceCod => 'الدفع عند الاستلام';

  @override
  String get servicePayAtPickup => 'الدفع عند الاستلام من الفرع';

  @override
  String branchSelectedToast(String name) {
    return 'تتسوّق الآن من $name';
  }

  @override
  String get branchSearchHint => 'ابحث عن صالة عرض أو منطقة';

  @override
  String get noBranches => 'لا توجد صالات عرض مطابقة.';

  @override
  String get branchChangeNote => 'ستتحدّث الأسعار والمخزون وفق هذا الفرع، دون أن تتأثر الطلبات الحالية.';

  @override
  String get mapAttribution => '© مساهمو OpenStreetMap';

  @override
  String get cartTitle => 'سلّتك';

  @override
  String get cartEmpty => 'سلّتك فارغة';

  @override
  String get cartEmptyMessage => 'ستجد هنا القطع التي تضيفها، جاهزة متى شئت.';

  @override
  String get startShopping => 'ابدأ التسوّق';

  @override
  String get subtotal => 'المجموع الفرعي';

  @override
  String itemsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count قطعة',
      many: '$count قطعة',
      few: '$count قطع',
      two: 'قطعتان',
      one: 'قطعة واحدة',
      zero: 'لا قطع',
    );
    return '$_temp0';
  }

  @override
  String get couponCode => 'رمز الخصم';

  @override
  String get applyCoupon => 'تطبيق';

  @override
  String get removeCoupon => 'إزالة الرمز';

  @override
  String couponApplied(String code) {
    return 'تم تطبيق الرمز $code';
  }

  @override
  String get proceedToCheckout => 'المتابعة لإتمام الطلب';

  @override
  String get itemUnavailable => 'غير متاح في هذا الفرع';

  @override
  String itemInsufficientStock(int count) {
    return 'المتوفر $count فقط — يُرجى تقليل الكمية';
  }

  @override
  String get itemInactive => 'لم يعد متاحًا';

  @override
  String priceChangedFrom(String price) {
    return 'تغيّر السعر من $price';
  }

  @override
  String get removeItemConfirm => 'هل تريد إزالة هذه القطعة من السلة؟';

  @override
  String get cartHasIssues => 'بعض القطع تحتاج إلى مراجعتك قبل إتمام الطلب.';

  @override
  String get totalsCalculatedAtCheckout => 'تُحتسب رسوم التوصيل والإجمالي النهائي عند إتمام الطلب.';

  @override
  String get checkoutTitle => 'إتمام الطلب';

  @override
  String get fulfillmentMethod => 'كيف تفضّل استلام طلبك؟';

  @override
  String get delivery => 'التوصيل';

  @override
  String get pickup => 'الاستلام';

  @override
  String get deliveryAddress => 'عنوان التوصيل';

  @override
  String get addAddress => 'إضافة عنوان';

  @override
  String get pickupBranch => 'صالة الاستلام';

  @override
  String get pickupSlot => 'موعد الاستلام';

  @override
  String get noSlots => 'لا توجد مواعيد استلام في هذا اليوم.';

  @override
  String get slotFull => 'مكتمل';

  @override
  String slotRemaining(int count) {
    return 'متبقٍّ $count';
  }

  @override
  String get paymentMethod => 'الدفع';

  @override
  String get paySadad => 'الدفع الإلكتروني عبر سداد';

  @override
  String get payCod => 'الدفع نقدًا عند التوصيل';

  @override
  String get payAtPickup => 'الدفع عند الاستلام من الفرع';

  @override
  String get paymentUnavailable => 'غير متاح لهذا الطلب';

  @override
  String get paymentReasonCodLimit => 'قيمة الطلب تتجاوز حد الدفع عند التوصيل';

  @override
  String get loyaltyPoints => 'نقاط الولاء';

  @override
  String get usePoints => 'استبدال النقاط';

  @override
  String pointsBalance(int points) {
    return 'الرصيد: $points نقطة';
  }

  @override
  String maxRedeemable(int points) {
    return 'حتى $points نقطة لهذا الطلب';
  }

  @override
  String pointsToEarn(int points) {
    return 'ستكسب $points نقطة';
  }

  @override
  String get orderSummary => 'ملخّص الطلب';

  @override
  String get discount => 'الخصم';

  @override
  String get deliveryFee => 'رسوم التوصيل';

  @override
  String get serviceFee => 'رسوم الخدمة';

  @override
  String get codFee => 'رسوم الدفع عند التوصيل';

  @override
  String get tax => 'الضريبة';

  @override
  String get loyaltyDiscount => 'خصم النقاط';

  @override
  String get grandTotal => 'الإجمالي';

  @override
  String splitFulfillmentTitle(int count) {
    return 'سيصلك طلبك على $count دفعات';
  }

  @override
  String get splitFulfillmentMessage => 'تُجهَّز القطع في أكثر من صالة عرض لتصلك بشكل أسرع.';

  @override
  String fulfilledFrom(String branch) {
    return 'من $branch';
  }

  @override
  String etaMinutes(String time) {
    return 'الوقت المتوقع $time';
  }

  @override
  String hoursShort(int hours) {
    return '$hours س';
  }

  @override
  String minutesShort(int minutes) {
    return '$minutes د';
  }

  @override
  String get allowSplit => 'السماح بتقسيم الشحنات';

  @override
  String get allowSplitHint => 'استلام القطع من أكثر من صالة عرض';

  @override
  String get customerNotes => 'ملاحظات لفريقنا';

  @override
  String get placeOrder => 'تأكيد الطلب';

  @override
  String get quoteChangedTitle => 'تم تحديث المبالغ';

  @override
  String get quoteChangedMessage =>
      'تغيّرت الأسعار أو المخزون أو الرسوم منذ بدء إتمام الطلب. يُرجى مراجعة المبالغ الجديدة.';

  @override
  String get reviewAndConfirm => 'مراجعة وتأكيد';

  @override
  String previousTotal(String amount) {
    return 'كان $amount';
  }

  @override
  String get cannotPlaceOrder => 'لا يمكن تأكيد الطلب حاليًا';

  @override
  String get orderPlaced => 'شكرًا لك!';

  @override
  String orderPlacedMessage(String number) {
    return 'تم تأكيد الطلب $number.';
  }

  @override
  String get viewOrder => 'عرض الطلب';

  @override
  String get continueShopping => 'متابعة التسوّق';

  @override
  String get selectAddressFirst => 'اختر عنوان التوصيل';

  @override
  String get selectBranchFirst => 'اختر صالة الاستلام';

  @override
  String get selectPaymentFirst => 'اختر طريقة الدفع';

  @override
  String get calculating => 'جارٍ الحساب…';

  @override
  String get free => 'مجانًا';

  @override
  String get deliveryDate => 'تاريخ التوصيل';

  @override
  String get paymentTitle => 'الدفع';

  @override
  String get paymentPending => 'بانتظار تأكيد الدفع…';

  @override
  String get paymentPendingHint => 'قد يستغرق ذلك لحظات. يُرجى عدم إغلاق التطبيق.';

  @override
  String get paymentPaid => 'تم استلام الدفعة';

  @override
  String get paymentFailed => 'لم تكتمل عملية الدفع';

  @override
  String get paymentExpired => 'انتهت صلاحية جلسة الدفع';

  @override
  String get paymentCancelled => 'تم إلغاء الدفع';

  @override
  String get paymentStillPending => 'لم يصلنا التأكيد بعد. يمكنك التحقق مجددًا أو عرض طلبك.';

  @override
  String get checkAgain => 'تحقّق مجددًا';

  @override
  String get retryPayment => 'إعادة محاولة الدفع';

  @override
  String get payNow => 'ادفع الآن';

  @override
  String get openingPayment => 'جارٍ فتح صفحة سداد الآمنة…';

  @override
  String get ordersTitle => 'طلباتي';

  @override
  String get noOrders => 'لا توجد طلبات بعد';

  @override
  String get noOrdersMessage => 'عندما تطلب، يمكنك متابعة طلبك من هنا.';

  @override
  String orderNumber(String number) {
    return 'الطلب $number';
  }

  @override
  String placedOn(String date) {
    return 'بتاريخ $date';
  }

  @override
  String get orderItems => 'القطع';

  @override
  String get orderTimeline => 'مراحل الطلب';

  @override
  String get cancelOrder => 'إلغاء الطلب';

  @override
  String get cancelOrderTitle => 'هل تريد إلغاء هذا الطلب؟';

  @override
  String get cancelReason => 'السبب';

  @override
  String get cancelReasonChangedMind => 'غيّرت رأيي';

  @override
  String get cancelReasonMistake => 'طلبته بالخطأ';

  @override
  String get cancelReasonDelay => 'التوصيل يستغرق وقتًا طويلًا';

  @override
  String get cancelReasonPrice => 'وجدت سعرًا أفضل';

  @override
  String get cancelReasonOther => 'سبب آخر';

  @override
  String get cancelDetails => 'أخبرنا بالمزيد (اختياري)';

  @override
  String get cancelRequested => 'تم طلب الإلغاء، وسنؤكده قريبًا.';

  @override
  String get cancelApproved => 'تم إلغاء طلبك.';

  @override
  String get keepOrder => 'الإبقاء على الطلب';

  @override
  String get reorder => 'اطلب مجددًا';

  @override
  String get reorderDone => 'تمت إضافة القطع المتاحة إلى سلتك.';

  @override
  String reorderSkipped(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count قطعة لم تعد متاحة.',
      many: '$count قطعة لم تعد متاحة.',
      few: '$count قطع لم تعد متاحة.',
      two: 'قطعتان لم تعودا متاحتين.',
      one: 'قطعة واحدة لم تعد متاحة.',
    );
    return '$_temp0';
  }

  @override
  String get downloadInvoice => 'الفاتورة (PDF)';

  @override
  String get pickupPass => 'تصريح الاستلام';

  @override
  String get pickupPassTitle => 'تصريح الاستلام الخاص بك';

  @override
  String get pickupPassInstructions => 'أظهر هذا الرمز لدى موظف الصالة لاستلام طلبك.';

  @override
  String passExpires(String time) {
    return 'صالح حتى $time';
  }

  @override
  String get trackDelivery => 'تتبّع التوصيل';

  @override
  String get driver => 'السائق';

  @override
  String get trackingNumber => 'رقم التتبع';

  @override
  String get deliveryProvider => 'شركة التوصيل';

  @override
  String get eta => 'الوصول المتوقع';

  @override
  String shipment(String number) {
    return 'الشحنة $number';
  }

  @override
  String get shippingTo => 'التوصيل إلى';

  @override
  String get paymentLabel => 'الدفع';

  @override
  String get filterAll => 'الكل';

  @override
  String get filterActive => 'الجارية';

  @override
  String get filterCompleted => 'المكتملة';

  @override
  String get filterCancelled => 'الملغاة';

  @override
  String get contactSupport => 'المساعدة بخصوص هذا الطلب';

  @override
  String get requestReturn => 'طلب إرجاع';

  @override
  String get returnReasonDamaged => 'وصل تالفًا';

  @override
  String get returnReasonWrong => 'قطعة غير صحيحة';

  @override
  String get returnReasonNotAsDescribed => 'لا يطابق الوصف';

  @override
  String get returnRequested => 'تم طلب الإرجاع، وسيتواصل معك فريقنا.';

  @override
  String get selectItemsToReturn => 'اختر القطع المراد إرجاعها';

  @override
  String slotLabel(String date, String from, String to) {
    return '$date، $from–$to';
  }

  @override
  String get wishlistTitle => 'المفضلة';

  @override
  String get wishlistEmpty => 'لا توجد عناصر محفوظة';

  @override
  String get wishlistEmptyMessage => 'اضغط على رمز القلب في أي قطعة لحفظها هنا.';

  @override
  String get addedToWishlist => 'تم الحفظ في المفضلة';

  @override
  String get removedFromWishlist => 'تمت الإزالة من المفضلة';

  @override
  String get auctionsTitle => 'المزادات';

  @override
  String get auctionUpcoming => 'القادمة';

  @override
  String get auctionLive => 'مباشر';

  @override
  String get auctionCompleted => 'المنتهية';

  @override
  String get noAuctions => 'لا توجد مزادات حاليًا';

  @override
  String get noAuctionsMessage => 'فعّل إشعارات المزادات ليصلك موعد المزاد القادم.';

  @override
  String lotsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count قطعة',
      many: '$count قطعة',
      few: '$count قطع',
      two: 'قطعتان',
      one: 'قطعة واحدة',
    );
    return '$_temp0';
  }

  @override
  String lotNumber(int number) {
    return 'القطعة رقم $number';
  }

  @override
  String get currentBid => 'المزايدة الحالية';

  @override
  String get startingPrice => 'السعر الافتتاحي';

  @override
  String get minNextBid => 'الحد الأدنى للمزايدة التالية';

  @override
  String get minIncrement => 'قيمة الزيادة';

  @override
  String get buyNowPrice => 'اشترِ الآن';

  @override
  String get reserveMet => 'تم بلوغ السعر الأدنى';

  @override
  String get reserveNotMet => 'لم يُبلغ السعر الأدنى';

  @override
  String bidCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count مزايدة',
      many: '$count مزايدة',
      few: '$count مزايدات',
      two: 'مزايدتان',
      one: 'مزايدة واحدة',
      zero: 'لا مزايدات',
    );
    return '$_temp0';
  }

  @override
  String get placeBid => 'زايِد';

  @override
  String get bidAmount => 'مبلغ المزايدة (ر.ق)';

  @override
  String get bidPlaced => 'تم تقديم مزايدتك';

  @override
  String bidTooLow(String amount) {
    return 'يجب ألا تقل المزايدة عن $amount';
  }

  @override
  String get youAreLeading => 'أنت صاحب أعلى مزايدة';

  @override
  String get youWereOutbid => 'تجاوزك مزايد آخر';

  @override
  String get youWon => 'فزت بهذه القطعة';

  @override
  String get youLost => 'ذهبت هذه القطعة لمزايد آخر';

  @override
  String get autoBid => 'المزايدة التلقائية';

  @override
  String get autoBidHint => 'سنزايد نيابةً عنك بزيادة واحدة في كل مرة حتى الحد الأقصى الذي تحدده.';

  @override
  String get autoBidMax => 'الحد الأقصى (ر.ق)';

  @override
  String autoBidActive(String amount) {
    return 'مزايدة تلقائية حتى $amount';
  }

  @override
  String get setAutoBid => 'تفعيل المزايدة التلقائية';

  @override
  String get cancelAutoBid => 'إيقاف المزايدة التلقائية';

  @override
  String get watch => 'متابعة';

  @override
  String get watching => 'تتم المتابعة';

  @override
  String get registerToBid => 'سجّل للمزايدة';

  @override
  String get acceptTerms => 'قرأت شروط المزاد وأوافق عليها';

  @override
  String get auctionTerms => 'شروط المزاد';

  @override
  String registeredPaddle(String paddle) {
    return 'مسجّل · رقم المزايد $paddle';
  }

  @override
  String get registrationPending => 'التسجيل بانتظار الموافقة';

  @override
  String depositRequired(String amount) {
    return 'يلزم دفع تأمين مسترد بقيمة $amount';
  }

  @override
  String get payDeposit => 'دفع التأمين';

  @override
  String get bidHistory => 'سجل المزايدات';

  @override
  String get noBids => 'لا مزايدات بعد — كن الأول.';

  @override
  String get lotExtended => 'تم تمديد الوقت';

  @override
  String get lotEnded => 'انتهت المزايدة';

  @override
  String get lotNotStarted => 'لم تبدأ المزايدة بعد';

  @override
  String get lotStatusScheduled => 'مجدول';

  @override
  String get lotStatusOpen => 'مفتوح';

  @override
  String get lotStatusClosing => 'يُغلق قريبًا';

  @override
  String get lotStatusClosed => 'مغلق';

  @override
  String get lotStatusSold => 'مُباع';

  @override
  String get lotStatusUnsold => 'لم يُبع';

  @override
  String get lotStatusCancelled => 'ملغى';

  @override
  String get myAuctions => 'مزاداتي';

  @override
  String get myBids => 'مزايداتي';

  @override
  String get myWatchlist => 'المتابَعة';

  @override
  String get myWins => 'الفائزة';

  @override
  String get checkoutWin => 'إتمام الشراء';

  @override
  String get winCheckoutTitle => 'أكمِل شراء القطعة';

  @override
  String get realtimeLive => 'مباشر';

  @override
  String get realtimeReconnecting => 'جارٍ إعادة الاتصال…';

  @override
  String get confirmBidTitle => 'تأكيد المزايدة';

  @override
  String confirmBidMessage(String amount) {
    return 'هل تريد المزايدة بمبلغ $amount؟ المزايدات مُلزِمة.';
  }

  @override
  String get lots => 'القطع المعروضة';

  @override
  String get viewLot => 'عرض القطعة';

  @override
  String auctionStartsAt(String date) {
    return 'يبدأ $date';
  }

  @override
  String auctionEndedAt(String date) {
    return 'انتهى $date';
  }

  @override
  String get winningBid => 'المزايدة الفائزة';

  @override
  String payBy(String date) {
    return 'يُستحق الدفع قبل $date';
  }

  @override
  String get watchAdded => 'أُضيفت إلى قائمة المتابعة';

  @override
  String get watchRemoved => 'أُزيلت من قائمة المتابعة';

  @override
  String get notificationsTitle => 'الإشعارات';

  @override
  String get noNotifications => 'لا جديد حاليًا';

  @override
  String get noNotificationsMessage => 'ستظهر هنا تحديثات الطلبات وتنبيهات المزادات.';

  @override
  String get markAllRead => 'تحديد الكل كمقروء';

  @override
  String get accountTitle => 'حسابي';

  @override
  String get guestAccountTitle => 'اجعلها تجربتك الخاصة';

  @override
  String get guestAccountMessage => 'سجّل دخولك لتتابع طلباتك وتحفظ مفضّلاتك وتشارك في المزادات.';

  @override
  String get myOrders => 'الطلبات';

  @override
  String get myAddresses => 'العناوين';

  @override
  String get myWishlist => 'المفضلة';

  @override
  String get loyaltyAndReferral => 'المكافآت والإحالات';

  @override
  String get notificationPreferences => 'تفضيلات الإشعارات';

  @override
  String get support => 'المساعدة والدعم';

  @override
  String get settings => 'الإعدادات';

  @override
  String get editProfile => 'تعديل الملف الشخصي';

  @override
  String get profileSaved => 'تم تحديث الملف الشخصي';

  @override
  String get dateOfBirth => 'تاريخ الميلاد';

  @override
  String get gender => 'الجنس';

  @override
  String get male => 'ذكر';

  @override
  String get female => 'أنثى';

  @override
  String get notSpecified => 'غير محدد';

  @override
  String get changePassword => 'تغيير كلمة المرور';

  @override
  String get setPassword => 'تعيين كلمة مرور';

  @override
  String get currentPassword => 'كلمة المرور الحالية';

  @override
  String get passwordChanged => 'تم تحديث كلمة المرور';

  @override
  String memberSince(String date) {
    return 'عضو منذ $date';
  }

  @override
  String get addressesTitle => 'العناوين المحفوظة';

  @override
  String get noAddresses => 'لا توجد عناوين محفوظة';

  @override
  String get noAddressesMessage => 'أضف عنوانًا لإتمام طلباتك بسرعة.';

  @override
  String get newAddress => 'عنوان جديد';

  @override
  String get editAddress => 'تعديل العنوان';

  @override
  String get addressLabel => 'اسم العنوان';

  @override
  String get addressLabelHint => 'المنزل، المكتب…';

  @override
  String get recipientName => 'اسم المستلم';

  @override
  String get recipientPhone => 'جوال المستلم';

  @override
  String get deliveryZone => 'منطقة التوصيل';

  @override
  String get zoneNumber => 'المنطقة';

  @override
  String get streetNumber => 'الشارع';

  @override
  String get buildingNumber => 'المبنى';

  @override
  String get unitNumber => 'الوحدة / الشقة';

  @override
  String get area => 'الحي';

  @override
  String get city => 'المدينة';

  @override
  String get landmark => 'معلم قريب';

  @override
  String get instructions => 'تعليمات التوصيل';

  @override
  String get pinOnMap => 'تحديد الموقع على الخريطة';

  @override
  String get pinSet => 'تم تحديد الموقع';

  @override
  String get moveMapToPin => 'حرّك الخريطة لوضع الدبوس عند مدخل منزلك';

  @override
  String get confirmLocation => 'تأكيد الموقع';

  @override
  String get setAsDefault => 'تعيين كافتراضي';

  @override
  String get defaultAddress => 'افتراضي';

  @override
  String get deleteAddressConfirm => 'هل تريد حذف هذا العنوان؟';

  @override
  String get addressSaved => 'تم حفظ العنوان';

  @override
  String get addressRequirement => 'أدخل أرقام المنطقة والشارع والمبنى، أو حدّد موقعك على الخريطة.';

  @override
  String get useCurrentLocation => 'استخدام موقعي الحالي';

  @override
  String get saveAddress => 'حفظ العنوان';

  @override
  String get qatarAddressHint => 'تجد هذه الأرقام على اللوحة الزرقاء بجوار مدخلك.';

  @override
  String get channelPush => 'إشعارات التطبيق';

  @override
  String get channelSms => 'رسائل نصية';

  @override
  String get channelWhatsapp => 'واتساب';

  @override
  String get channelEmail => 'البريد الإلكتروني';

  @override
  String get categoryTransactional => 'الطلبات والحساب';

  @override
  String get categoryTransactionalDesc => 'تحديثات الطلبات والدفع والتوصيل والأمان';

  @override
  String get categoryMarketing => 'العروض والإلهام';

  @override
  String get categoryMarketingDesc => 'التشكيلات الجديدة والعروض والفعاليات';

  @override
  String get categoryAuction => 'المزادات';

  @override
  String get categoryAuctionDesc => 'تنبيهات تجاوز المزايدة والفوز والمزادات القادمة';

  @override
  String get prefsSaved => 'تم حفظ التفضيلات';

  @override
  String get transactionalNote => 'بعض رسائل الطلبات الأساسية تُرسل دائمًا.';

  @override
  String get loyaltyTitle => 'المكافآت';

  @override
  String pointsUnit(int points) {
    return '$points نقطة';
  }

  @override
  String tierLabel(String tier) {
    return 'عضوية $tier';
  }

  @override
  String pointsWorth(String value) {
    return 'بقيمة $value';
  }

  @override
  String lifetimeEarned(int points) {
    return 'إجمالي المكتسب: $points نقطة';
  }

  @override
  String get loyaltyHistory => 'سجل النقاط';

  @override
  String get noLoyaltyHistory => 'اكسب النقاط مع كل عملية شراء.';

  @override
  String pointsExpire(String date) {
    return 'تنتهي $date';
  }

  @override
  String get referralTitle => 'ادعُ أصدقاءك';

  @override
  String get referralBody => 'شارك رمزك، وعندما يتسوّق أصدقاؤك تكسبون المكافآت معًا.';

  @override
  String get yourCode => 'رمزك';

  @override
  String get shareReferral => 'مشاركة الدعوة';

  @override
  String referralShareText(String code, String url) {
    return 'اكتشف البحر للمنزل والمعيشة. استخدم رمزي $code: $url';
  }

  @override
  String get referredCount => 'أصدقاء انضموا';

  @override
  String get rewardedCount => 'مكافآت مكتسبة';

  @override
  String get writeReviewTitle => 'تقييمك';

  @override
  String get yourRating => 'كيف تقيّم المنتج؟';

  @override
  String get reviewTitleLabel => 'العنوان';

  @override
  String get reviewBodyLabel => 'شارك تجربتك مع الآخرين';

  @override
  String get addPhotos => 'إضافة صور';

  @override
  String maxPhotos(int count) {
    return 'حتى $count صور';
  }

  @override
  String get submitReview => 'إرسال التقييم';

  @override
  String get reviewSubmitted => 'شكرًا لك! سيظهر تقييمك بعد مراجعته.';

  @override
  String get ratingRequired => 'يُرجى اختيار التقييم.';

  @override
  String get removePhoto => 'إزالة الصورة';

  @override
  String get fromCamera => 'الكاميرا';

  @override
  String get fromGallery => 'مكتبة الصور';

  @override
  String starLabel(int count) {
    return '$count نجوم';
  }

  @override
  String get supportTitle => 'المساعدة والدعم';

  @override
  String get newTicket => 'طلب جديد';

  @override
  String get noTickets => 'لا توجد طلبات دعم';

  @override
  String get noTicketsMessage => 'تحتاج مساعدة؟ يردّ فريقنا بالعربية والإنجليزية.';

  @override
  String get ticketCategory => 'الموضوع';

  @override
  String get ticketCategoryOrder => 'طلب';

  @override
  String get ticketCategoryPayment => 'الدفع أو الاسترداد';

  @override
  String get ticketCategoryDelivery => 'التوصيل أو الاستلام';

  @override
  String get ticketCategoryProduct => 'منتج';

  @override
  String get ticketCategoryAuction => 'المزادات';

  @override
  String get ticketCategoryAccount => 'حسابي';

  @override
  String get ticketCategoryOther => 'أمر آخر';

  @override
  String get ticketSubject => 'العنوان';

  @override
  String get ticketMessage => 'كيف يمكننا مساعدتك؟';

  @override
  String get relatedOrder => 'الطلب المرتبط';

  @override
  String get noRelatedOrder => 'لا يوجد';

  @override
  String get ticketCreated => 'تم إرسال طلبك، وسنرد عليك هنا.';

  @override
  String get typeMessage => 'اكتب رسالة…';

  @override
  String get send => 'إرسال';

  @override
  String get ticketStatusOpen => 'مفتوح';

  @override
  String get ticketStatusPending => 'بانتظار الرد';

  @override
  String get ticketStatusResolved => 'تم الحل';

  @override
  String get ticketStatusClosed => 'مغلق';

  @override
  String get supportTeam => 'فريق دعم البحر';

  @override
  String get you => 'أنت';

  @override
  String get settingsTitle => 'الإعدادات';

  @override
  String get language => 'اللغة';

  @override
  String get appearance => 'المظهر';

  @override
  String get themeSystem => 'حسب الجهاز';

  @override
  String get themeLight => 'فاتح';

  @override
  String get themeDark => 'داكن';

  @override
  String get legal => 'عن التطبيق والشؤون القانونية';

  @override
  String get termsOfService => 'شروط الخدمة';

  @override
  String get privacyPolicy => 'سياسة الخصوصية';

  @override
  String get aboutUs => 'عن البحر';

  @override
  String get faq => 'الأسئلة الشائعة';

  @override
  String get returnsPolicy => 'سياسة الإرجاع';

  @override
  String get contactUs => 'تواصل معنا';

  @override
  String appVersion(String version) {
    return 'الإصدار $version';
  }

  @override
  String get privacy => 'الخصوصية';

  @override
  String get deleteAccount => 'حذف الحساب';

  @override
  String get deleteAccountTitle => 'هل تريد حذف حسابك؟';

  @override
  String get deleteAccountWarning =>
      'ستتم جدولة حذف حسابك بعد مهلة 14 يومًا، تُجهَّل بعدها بياناتك الشخصية مع الاحتفاظ بسجلات الطلبات والفواتير المطلوبة قانونًا. يمكنك إلغاء الطلب في أي وقت قبل ذلك.';

  @override
  String get deleteAccountReason => 'لماذا تغادرنا؟ (اختياري)';

  @override
  String deleteAccountTypeToConfirm(String word) {
    return 'اكتب $word للتأكيد';
  }

  @override
  String get deleteWord => 'حذف';

  @override
  String deleteAccountRequested(String date) {
    return 'تمت جدولة الحذف في $date';
  }

  @override
  String get cancelDeletion => 'إلغاء طلب الحذف';

  @override
  String get deletionCancelled => 'لن يتم حذف حسابك.';

  @override
  String get logoutConfirm => 'هل تريد تسجيل الخروج من هذا الجهاز؟';

  @override
  String get logoutAllDevices => 'تسجيل الخروج من جميع الأجهزة';

  @override
  String get marketingConsentToggle => 'الرسائل التسويقية';

  @override
  String get paymentStatusPending => 'قيد الانتظار';

  @override
  String get paymentStatusPaid => 'مدفوع';

  @override
  String get paymentStatusFailed => 'فشل';

  @override
  String get paymentStatusRefunded => 'مسترد';

  @override
  String paymentMethodLabel(String method) {
    return '$method';
  }

  @override
  String get deliverySlotTitle => 'وقت التوصيل';

  @override
  String get deliverySlotMoreDates => 'تواريخ أخرى';

  @override
  String get deliverySlotNoDates => 'لا توجد مواعيد توصيل خلال الأيام السبعة القادمة. جرّب «تواريخ أخرى».';

  @override
  String get deliverySlotNoSlotsDay => 'لا توجد مواعيد توصيل في هذا اليوم.';

  @override
  String get deliverySlotRequiredHint => 'اختر موعد التوصيل لإتمام الطلب';

  @override
  String get deliverySlotRequiredError => 'يرجى اختيار موعد التوصيل لهذا الطلب.';

  @override
  String get deliverySlotUnavailableError => 'لم يعد موعد التوصيل هذا متاحًا. يرجى اختيار موعد آخر.';

  @override
  String deliverySlotSummary(String date, String from, String to) {
    return 'التوصيل: $date، $from – $to';
  }

  @override
  String get coloursTitle => 'الألوان';

  @override
  String get coloursHint => 'اختر الكمية لكل لون';

  @override
  String addItemsToBag(int count) {
    return 'أضف $count إلى الحقيبة';
  }

  @override
  String get colourOutOfStock => 'غير متوفر';

  @override
  String get pickAtLeastOne => 'اختر كمية للون واحد على الأقل';

  @override
  String didYouMean(String term) {
    return 'هل تقصد «$term»؟';
  }

  @override
  String searchSeeAll(String term) {
    return 'عرض كل النتائج لـ «$term»';
  }

  @override
  String get buyAgainTitle => 'اشترِ مرة أخرى';

  @override
  String get buyAgainAdd => 'أضف';

  @override
  String buyAgainBought(int count) {
    return 'اشتريته $count مرات';
  }
}
