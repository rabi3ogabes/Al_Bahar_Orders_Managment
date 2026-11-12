import 'package:flutter/widgets.dart';

/// English / Arabic text for the two staff apps (kept small and local; the customer app uses ARB files).
class OpsStrings {
  const OpsStrings(this.code);
  final String code;

  static OpsStrings of(BuildContext context) => OpsStrings(Localizations.localeOf(context).languageCode);

  bool get ar => code == 'ar';
  String _t(String en, String arText) => ar ? arText : en;

  String get driverApp => _t('Al Bahar Driver', 'سائق البحار');
  String get staffApp => _t('Al Bahar Branch', 'موظف فرع البحار');
  String get signIn => _t('Sign in', 'تسجيل الدخول');
  String get email => _t('Email', 'البريد الإلكتروني');
  String get password => _t('Password', 'كلمة المرور');
  String get signOut => _t('Sign out', 'تسجيل الخروج');
  String get signInHintDriver =>
      _t('Use the account your branch manager created for you.', 'استخدم الحساب الذي أنشأه لك مدير الفرع.');
  String get signInHintStaff => _t('Sign in with your branch staff account.', 'سجّل الدخول بحساب موظف الفرع.');
  String get notDriver => _t('This account is not set up as a delivery driver.', 'هذا الحساب غير مفعّل كسائق توصيل.');
  String get notStaff =>
      _t('This account cannot manage branch orders.', 'هذا الحساب لا يملك صلاحية إدارة طلبات الفرع.');
  String get retry => _t('Try again', 'حاول مرة أخرى');
  String get refresh => _t('Refresh', 'تحديث');

  // Driver
  String get onDuty => _t('On duty', 'متواجد');
  String get offDuty => _t('Off duty', 'غير متواجد');
  String get dutyOnHint => _t('You will receive deliveries from your branches.', 'ستصلك طلبات التوصيل من فروعك.');
  String get dutyOffHint => _t('Go on duty to receive deliveries.', 'فعّل التواجد لاستلام الطلبات.');
  String get trackingTitle => _t('Sharing your live location', 'مشاركة موقعك المباشر');
  String get trackingBody => _t('Customers and dispatch can follow your delivery.', 'يتابع العملاء والمرسل توصيلك.');
  String get myJobs => _t('My deliveries', 'توصيلاتي');
  String get history => _t('History', 'السجل');
  String get noJobs => _t('No deliveries yet', 'لا توجد توصيلات الآن');
  String get noJobsHint => _t(
    'New deliveries appear here as soon as they are assigned to you.',
    'تظهر التوصيلات الجديدة هنا فور إسنادها إليك.',
  );
  String get cod => _t('Collect cash', 'تحصيل نقدي');
  String get call => _t('Call', 'اتصال');
  String get navigate => _t('Navigate', 'الاتجاه');
  String get accept => _t('Accept', 'قبول');
  String get pickedUp => _t('Picked up from branch', 'تم الاستلام من الفرع');
  String get onTheWay => _t('On my way', 'في الطريق');
  String get delivered => _t('Delivered', 'تم التسليم');
  String get failed => _t('Could not deliver', 'تعذّر التسليم');
  String get receivedBy => _t('Received by', 'استلم الطلب');
  String get takePhoto => _t('Take proof photo', 'التقط صورة الإثبات');
  String get photoTaken => _t('Photo added', 'تمت إضافة الصورة');
  String get photoRequired => _t('A proof photo is required.', 'صورة الإثبات مطلوبة.');
  String get cashCollected => _t('Cash collected', 'المبلغ المحصّل');
  String get confirm => _t('Confirm', 'تأكيد');
  String get cancel => _t('Cancel', 'إلغاء');
  String get reason => _t('Reason', 'السبب');
  String reasonLabel(String code) => switch (code) {
    'customer_unreachable' => _t('Customer unreachable', 'لا يمكن الوصول للعميل'),
    'customer_absent' => _t('Customer not there', 'العميل غير موجود'),
    'wrong_address' => _t('Wrong address', 'عنوان خاطئ'),
    'refused' => _t('Refused the order', 'رفض الطلب'),
    'damaged' => _t('Damaged', 'تالف'),
    'access_denied' => _t('No access', 'لا يمكن الدخول'),
    _ => _t('Other', 'أخرى'),
  };
  String statusLabel(String s) => switch (s) {
    'assigned' => _t('New', 'جديد'),
    'accepted' => _t('Accepted', 'تم القبول'),
    'picked_up' => _t('Picked up', 'تم الاستلام'),
    'out_for_delivery' => _t('On the way', 'في الطريق'),
    'delivered' => _t('Delivered', 'تم التسليم'),
    'failed' => _t('Failed', 'تعذّر'),
    'rescheduled' => _t('Rescheduled', 'أعيدت جدولته'),
    'returned' => _t('Returned', 'أُعيد'),
    'cancelled' => _t('Cancelled', 'ملغي'),
    'pending' => _t('Waiting for a driver', 'بانتظار سائق'),
    _ => s,
  };

  String newJobAlert(String number) => _t('New delivery #$number assigned to you', 'تم إسناد توصيل جديد #$number إليك');
  String newOrderAlert(String number) => _t('New order #$number', 'طلب جديد #$number');
  String get liveUpdate => _t('Live', 'مباشر');

  // Staff
  String get orders => _t('Orders', 'الطلبات');
  String get drivers => _t('Drivers', 'السائقون');
  String get stageNew => _t('New', 'جديدة');
  String get stagePreparing => _t('Preparing', 'قيد التحضير');
  String get stageReady => _t('Ready', 'جاهزة');
  String get stageOut => _t('On the way', 'في الطريق');
  String get waitingForDriver => _t('Waiting for a driver', 'بانتظار سائق');
  String get driversOnDuty => _t('Drivers on duty', 'سائقون متواجدون');
  String get autoDispatch => _t(
    'Auto dispatch: ready orders go to the least busy driver.',
    'التوزيع التلقائي: تُسند الطلبات الجاهزة للسائق الأقل انشغالاً.',
  );
  String get manualDispatch => _t('You assign drivers to ready orders.', 'أنت تُسند السائقين للطلبات الجاهزة.');
  String get noOrders => _t('Nothing here right now', 'لا يوجد شيء هنا الآن');
  String get acceptOrder => _t('Accept order', 'قبول الطلب');
  String get startPreparing => _t('Start preparing', 'بدء التحضير');
  String get markReady => _t('Mark ready', 'جاهز للتسليم');
  String get assignDriver => _t('Assign driver', 'إسناد سائق');
  String get changeDriver => _t('Change driver', 'تغيير السائق');
  String get chooseDriver => _t('Choose a driver', 'اختر سائقاً');
  String get noDrivers => _t(
    'No drivers in this branch yet. Add them in the admin panel.',
    'لا يوجد سائقون في هذا الفرع بعد. أضفهم من لوحة الإدارة.',
  );
  String openJobs(int n) => _t('$n open', '$n مفتوحة');
  String items(int n) => _t('$n items', '$n قطعة');
  String assignedTo(String name) => _t('Driver: $name', 'السائق: $name');
  String get offDutyTag => _t('Off duty', 'غير متواجد');
  String get done => _t('Done', 'تم');
  // Add product
  String get addProduct => _t('Add product', 'إضافة منتج');
  String get newProduct => _t('New product', 'منتج جديد');
  String get photos => _t('Photos', 'الصور');
  String get addPhoto => _t('Add photo', 'إضافة صورة');
  String get takeProductPhoto => _t('Take a photo', 'التقاط صورة');
  String get fromGallery => _t('Choose from gallery', 'اختيار من المعرض');
  String get removePhoto => _t('Remove photo', 'حذف الصورة');
  String get photosHint => _t('Up to 8 photos. The first one is the cover.', 'حتى 8 صور. الأولى هي الغلاف.');
  String get titleEn => _t('Title (English)', 'العنوان (بالإنجليزية)');
  String get titleAr => _t('Title (Arabic)', 'العنوان (بالعربية)');
  String get price => _t('Price (QAR)', 'السعر (ر.ق)');
  String get category => _t('Category', 'الفئة');
  String get chooseCategory => _t('Choose a category', 'اختر فئة');
  String get searchCategory => _t('Search categories', 'ابحث في الفئات');
  String get noCategory => _t('No category', 'بدون فئة');
  String get description => _t('Description', 'الوصف');
  String get shortDescription => _t('Short description', 'وصف مختصر');
  String get writeWithAi => _t('Write the description with AI', 'اكتب الوصف بالذكاء الاصطناعي');
  String get writing => _t('Writing…', 'جارٍ الكتابة…');
  String get aiHint => _t(
    'Claude writes it from the title, in English and Arabic. Read it and change what you like.',
    'يكتبه Claude من العنوان بالعربية والإنجليزية. اقرأه وعدّل ما تشاء.',
  );
  String get aiNotReady => _t(
    'AI writing is not set up yet. Ask the admin to add the Claude key.',
    'الكتابة الذكية غير مفعّلة بعد. اطلب من المدير إضافة مفتاح Claude.',
  );
  String get needTitleFirst => _t('Write a title first.', 'اكتب العنوان أولاً.');
  String get saveDraft => _t('Save as draft', 'حفظ كمسودة');
  String get publishNow => _t('Publish now', 'نشر الآن');
  String get saveProduct => _t('Save product', 'حفظ المنتج');
  String get saving => _t('Saving…', 'جارٍ الحفظ…');
  String get studioOption => _t('Ask the photo studio to clean the background', 'اطلب من استوديو الصور تنظيف الخلفية');
  String get studioOptionHint => _t(
    'An edited copy appears in the dashboard for approval. Nothing goes live by itself.',
    'تظهر نسخة معدّلة في لوحة التحكم للموافقة. لا ينشر شيء تلقائياً.',
  );
  String get productSaved => _t('Product saved.', 'تم حفظ المنتج.');
  String get leaveQ => _t('Leave without saving?', 'المغادرة دون حفظ؟');
  String get leaveBody => _t('The photos and text you entered will be lost.', 'ستفقد الصور والنصوص التي أدخلتها.');
  String get leave => _t('Leave', 'مغادرة');
  String get stay => _t('Keep editing', 'متابعة التعديل');
  String get fieldRequired => _t('Required', 'مطلوب');
  String get priceInvalid => _t('Enter a price like 120 or 120.50', 'أدخل سعراً مثل 120 أو 120.50');
  String get close => _t('Close', 'إغلاق');
  String get language => _t('العربية', 'English');
}
