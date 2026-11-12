// ignore: unused_import
import 'package:intl/intl.dart' as intl;

import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

  @override
  String get appName => 'Al Bahar';

  @override
  String get appFullName => 'Al Bahar Home & Living';

  @override
  String get appTagline => 'Considered pieces for a beautiful home';

  @override
  String get retry => 'Try again';

  @override
  String get cancel => 'Cancel';

  @override
  String get confirm => 'Confirm';

  @override
  String get save => 'Save';

  @override
  String get delete => 'Delete';

  @override
  String get edit => 'Edit';

  @override
  String get close => 'Close';

  @override
  String get done => 'Done';

  @override
  String get next => 'Next';

  @override
  String get skip => 'Skip';

  @override
  String get continueLabel => 'Continue';

  @override
  String get apply => 'Apply';

  @override
  String get reset => 'Reset';

  @override
  String get clear => 'Clear';

  @override
  String get seeAll => 'See all';

  @override
  String get loading => 'Loading…';

  @override
  String get ok => 'OK';

  @override
  String get share => 'Share';

  @override
  String get copy => 'Copy';

  @override
  String get copied => 'Copied to clipboard';

  @override
  String get search => 'Search';

  @override
  String get optional => 'Optional';

  @override
  String get fieldRequired => 'This field is required';

  @override
  String get add => 'Add';

  @override
  String get remove => 'Remove';

  @override
  String get change => 'Change';

  @override
  String get call => 'Call';

  @override
  String get directions => 'Directions';

  @override
  String get signIn => 'Sign in';

  @override
  String get signOut => 'Sign out';

  @override
  String get signInRequiredTitle => 'Sign in to continue';

  @override
  String get signInRequiredMessage => 'Your cart, orders and wishlist are saved to your account.';

  @override
  String get back => 'Back';

  @override
  String get menu => 'More options';

  @override
  String get errorTitle => 'Something went wrong';

  @override
  String get errorGeneric => 'We couldn’t complete that. Please try again.';

  @override
  String get errorOffline => 'You appear to be offline. Check your connection and try again.';

  @override
  String get errorTimeout => 'The connection is taking too long. Please try again.';

  @override
  String get errorServer => 'Our service is having a moment. Please try again shortly.';

  @override
  String get errorNotFound => 'We couldn’t find what you were looking for.';

  @override
  String get errorForbidden => 'You don’t have access to this.';

  @override
  String errorRateLimited(int seconds) {
    return 'Too many attempts. Please wait $seconds seconds.';
  }

  @override
  String get errorSessionExpired => 'Your session has ended. Please sign in again.';

  @override
  String get offlineBanner => 'Offline — showing what we can';

  @override
  String get notFoundTitle => 'Not found';

  @override
  String requestReference(String id) {
    return 'Reference: $id';
  }

  @override
  String get navHome => 'Home';

  @override
  String get navCategories => 'Shop';

  @override
  String get navAuctions => 'Auctions';

  @override
  String get navCart => 'Cart';

  @override
  String get navAccount => 'Account';

  @override
  String get navOrders => 'Orders';

  @override
  String get navWishlist => 'Wishlist';

  @override
  String get navSearch => 'Search';

  @override
  String get navSupport => 'Help';

  @override
  String get navNotifications => 'Alerts';

  @override
  String get onboardingTitle1 => 'Living, beautifully considered';

  @override
  String get onboardingBody1 => 'Furniture, décor and essentials curated for homes across Qatar.';

  @override
  String get onboardingTitle2 => 'Your branch, your price';

  @override
  String get onboardingBody2 => 'See live stock and prices at the showroom nearest you, then deliver or pick up.';

  @override
  String get onboardingTitle3 => 'Bid on exceptional pieces';

  @override
  String get onboardingBody3 => 'Join live auctions for one-of-a-kind finds, with real-time bidding.';

  @override
  String get getStarted => 'Get started';

  @override
  String get chooseLanguageTitle => 'Choose your language';

  @override
  String get chooseLanguageSubtitle => 'You can change this any time in Settings.';

  @override
  String get languageArabic => 'العربية';

  @override
  String get languageEnglish => 'English';

  @override
  String get loginTitle => 'Welcome';

  @override
  String get loginSubtitle => 'Enter your Qatar mobile number and we’ll text you a verification code.';

  @override
  String get phoneLabel => 'Mobile number';

  @override
  String get phoneHint => '5512 3456';

  @override
  String get phoneInvalid => 'Please enter a valid Qatar mobile number.';

  @override
  String get sendCode => 'Send code';

  @override
  String get otpTitle => 'Verify your number';

  @override
  String otpSubtitle(String phone) {
    return 'Enter the 6-digit code sent to $phone';
  }

  @override
  String get otpCodeLabel => 'Verification code';

  @override
  String get verify => 'Verify';

  @override
  String get resendCode => 'Resend code';

  @override
  String resendIn(int seconds) {
    return 'Resend in ${seconds}s';
  }

  @override
  String get changeNumber => 'Change number';

  @override
  String get codeSent => 'A new code is on its way.';

  @override
  String get registerTitle => 'Create your account';

  @override
  String get registerSubtitle => 'Just a few details to personalise your experience.';

  @override
  String get nameLabel => 'Full name';

  @override
  String get emailLabel => 'Email';

  @override
  String get passwordLabel => 'Password';

  @override
  String get passwordConfirmLabel => 'Confirm password';

  @override
  String get passwordOptionalHint => 'Optional — lets you sign in without a code';

  @override
  String get referralCodeLabel => 'Referral code';

  @override
  String get marketingConsent => 'Send me offers, new collections and auction invitations';

  @override
  String get createAccount => 'Create account';

  @override
  String get loginWithPassword => 'Sign in with password';

  @override
  String get loginWithOtp => 'Sign in with a code instead';

  @override
  String get emailOrPhoneLabel => 'Mobile number or email';

  @override
  String get forgotPassword => 'Forgot password?';

  @override
  String get resetPasswordTitle => 'Reset password';

  @override
  String get resetPasswordSubtitle => 'We’ll text a code to your mobile number to confirm it’s you.';

  @override
  String get newPasswordLabel => 'New password';

  @override
  String get resetPassword => 'Reset password';

  @override
  String get passwordResetSuccess => 'Your password has been updated. Please sign in.';

  @override
  String get passwordsDoNotMatch => 'Passwords do not match.';

  @override
  String get passwordTooShort => 'Use at least 8 characters.';

  @override
  String get termsAgreementPrefix => 'By continuing you agree to our';

  @override
  String get termsAndPrivacy => 'Terms & Privacy Policy';

  @override
  String get continueAsGuest => 'Browse as guest';

  @override
  String welcomeBack(String name) {
    return 'Welcome back, $name';
  }

  @override
  String get emailInvalid => 'Please enter a valid email address.';

  @override
  String debugCodeHint(String code) {
    return 'Dev code: $code';
  }

  @override
  String get sendResetCode => 'Send reset code';

  @override
  String get homeGreeting => 'Good to see you';

  @override
  String homeGreetingNamed(String name) {
    return 'Hello, $name';
  }

  @override
  String get searchHint => 'Search detergents, tissues, cleaning…';

  @override
  String get homeEmpty => 'Our new collection is being arranged. Check back soon.';

  @override
  String endsIn(String time) {
    return 'Ends in $time';
  }

  @override
  String startsIn(String time) {
    return 'Starts in $time';
  }

  @override
  String get preferredBranch => 'Preferred branch';

  @override
  String get chooseBranch => 'Choose a branch';

  @override
  String shoppingAt(String branch) {
    return 'Shopping at $branch';
  }

  @override
  String get shopNow => 'Shop now';

  @override
  String countdownDays(int days) {
    return '${days}d';
  }

  @override
  String get categoriesTitle => 'Shop by category';

  @override
  String allInCategory(String category) {
    return 'All $category';
  }

  @override
  String get productsTitle => 'Products';

  @override
  String get filters => 'Filters';

  @override
  String get sortBy => 'Sort by';

  @override
  String get sortRelevance => 'Most relevant';

  @override
  String get sortPriceLowHigh => 'Price: low to high';

  @override
  String get sortPriceHighLow => 'Price: high to low';

  @override
  String get sortNewest => 'Newest';

  @override
  String get sortRating => 'Top rated';

  @override
  String get sortPopularity => 'Most popular';

  @override
  String get priceRange => 'Price (QAR)';

  @override
  String get minPrice => 'Min';

  @override
  String get maxPrice => 'Max';

  @override
  String get inStockOnly => 'In stock only';

  @override
  String get onSaleOnly => 'On sale';

  @override
  String get brandFilter => 'Brand';

  @override
  String get showResults => 'Show results';

  @override
  String get clearAll => 'Clear all';

  @override
  String get noProducts => 'Nothing here yet';

  @override
  String get noProductsMessage => 'Try adjusting your filters or exploring another category.';

  @override
  String resultsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count results',
      one: '1 result',
      zero: 'No results',
    );
    return '$_temp0';
  }

  @override
  String get searchTitle => 'Search';

  @override
  String get recentSearches => 'Recent searches';

  @override
  String get clearRecent => 'Clear';

  @override
  String get searchPrompt => 'Search in Arabic or English, by name, SKU or barcode.';

  @override
  String searchNoResults(String query) {
    return 'No results for “$query”';
  }

  @override
  String get badgeNew => 'New';

  @override
  String get badgeSale => 'Sale';

  @override
  String get badgeBestseller => 'Bestseller';

  @override
  String get outOfStock => 'Out of stock';

  @override
  String get inStock => 'In stock';

  @override
  String onlyLeft(int count) {
    return 'Only $count left';
  }

  @override
  String discountOff(int percent) {
    return '-$percent%';
  }

  @override
  String get subcategories => 'Browse';

  @override
  String get addToCart => 'Add to cart';

  @override
  String get addedToCart => 'Added to your cart';

  @override
  String get viewCart => 'View cart';

  @override
  String selectOption(String attribute) {
    return 'Select $attribute';
  }

  @override
  String get specifications => 'Specifications';

  @override
  String get dimensions => 'Dimensions';

  @override
  String get dimensionLength => 'Length';

  @override
  String get dimensionWidth => 'Width';

  @override
  String get dimensionHeight => 'Height';

  @override
  String get weight => 'Weight';

  @override
  String get warranty => 'Warranty';

  @override
  String warrantyMonths(int months) {
    String _temp0 = intl.Intl.pluralLogic(months, locale: localeName, other: '$months months', one: '1 month');
    return '$_temp0';
  }

  @override
  String warrantyBy(String provider) {
    return 'Provided by $provider';
  }

  @override
  String get assemblyRequired => 'Assembly required';

  @override
  String get assemblyAvailable => 'Assembly service available';

  @override
  String get oversizedItem => 'Large item — special delivery';

  @override
  String get description => 'Description';

  @override
  String get details => 'Details';

  @override
  String get reviews => 'Reviews';

  @override
  String reviewsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count reviews',
      one: '1 review',
      zero: 'No reviews',
    );
    return '$_temp0';
  }

  @override
  String get writeReview => 'Write a review';

  @override
  String get noReviews => 'Be the first to share your thoughts.';

  @override
  String get relatedProducts => 'You may also like';

  @override
  String get availability => 'Availability';

  @override
  String availableAt(String branch) {
    return 'Available at $branch';
  }

  @override
  String notInBranch(String branch) {
    return 'Not sold at $branch';
  }

  @override
  String outOfStockAt(String branch) {
    return 'Out of stock at $branch';
  }

  @override
  String get checkOtherBranches => 'Check other branches';

  @override
  String get branchAvailabilityTitle => 'Stock by branch';

  @override
  String availableQty(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count+ available',
      one: '1 available',
      zero: 'None available',
    );
    return '$_temp0';
  }

  @override
  String get deliveryAvailable => 'Home delivery';

  @override
  String get pickupAvailable => 'Store pickup';

  @override
  String get notifyBackInStock => 'Notify me when available';

  @override
  String get alertCreated => 'We’ll let you know.';

  @override
  String get addToWishlist => 'Add to wishlist';

  @override
  String get removeFromWishlist => 'Remove from wishlist';

  @override
  String saleEnds(String date) {
    return 'Offer ends $date';
  }

  @override
  String skuLabel(String sku) {
    return 'SKU $sku';
  }

  @override
  String get quantity => 'Quantity';

  @override
  String get increaseQuantity => 'Increase quantity';

  @override
  String get decreaseQuantity => 'Decrease quantity';

  @override
  String ratingLabel(String rating) {
    return 'Rated $rating out of 5';
  }

  @override
  String get verifiedPurchase => 'Verified purchase';

  @override
  String get reportReview => 'Report';

  @override
  String get reviewReported => 'Thanks — our team will review it.';

  @override
  String get productUnavailable => 'Currently unavailable';

  @override
  String get selectBranchToBuy => 'Choose a branch to see price & stock';

  @override
  String imageOf(int index, int total) {
    return 'Image $index of $total';
  }

  @override
  String get questions => 'Questions & answers';

  @override
  String get askQuestion => 'Ask a question';

  @override
  String get questionSubmitted => 'Your question was submitted.';

  @override
  String get noQuestions => 'No questions yet.';

  @override
  String get branchesTitle => 'Our showrooms';

  @override
  String get nearbyBranches => 'Nearby';

  @override
  String get allBranches => 'All';

  @override
  String get useMyLocation => 'Use my location';

  @override
  String get locationDenied => 'Location is off';

  @override
  String get locationDeniedMessage => 'Allow location access to find showrooms near you, or choose one from the list.';

  @override
  String get openSettings => 'Open settings';

  @override
  String get mapView => 'Map';

  @override
  String get listView => 'List';

  @override
  String get openNow => 'Open now';

  @override
  String get closedNow => 'Closed';

  @override
  String closesAt(String time) {
    return 'Closes $time';
  }

  @override
  String opensAt(String time) {
    return 'Opens $time';
  }

  @override
  String distanceKm(String km) {
    return '$km km';
  }

  @override
  String get selectThisBranch => 'Shop at this branch';

  @override
  String get selectedBranch => 'Your branch';

  @override
  String get branchHours => 'Opening hours';

  @override
  String get holidays => 'Holidays & special hours';

  @override
  String get closed => 'Closed';

  @override
  String breakTime(String from, String to) {
    return 'Break $from–$to';
  }

  @override
  String get branchServices => 'Services';

  @override
  String get serviceCod => 'Cash on delivery';

  @override
  String get servicePayAtPickup => 'Pay at pickup';

  @override
  String branchSelectedToast(String name) {
    return 'Now shopping at $name';
  }

  @override
  String get branchSearchHint => 'Search showrooms or areas';

  @override
  String get noBranches => 'No showrooms match your search.';

  @override
  String get branchChangeNote => 'Prices and stock will update for this branch. Existing orders are not affected.';

  @override
  String get mapAttribution => '© OpenStreetMap contributors';

  @override
  String get cartTitle => 'Your cart';

  @override
  String get cartEmpty => 'Your cart is empty';

  @override
  String get cartEmptyMessage => 'Pieces you add will be kept here, ready when you are.';

  @override
  String get startShopping => 'Start shopping';

  @override
  String get subtotal => 'Subtotal';

  @override
  String itemsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count items',
      one: '1 item',
      zero: 'No items',
    );
    return '$_temp0';
  }

  @override
  String get couponCode => 'Promo code';

  @override
  String get applyCoupon => 'Apply';

  @override
  String get removeCoupon => 'Remove code';

  @override
  String couponApplied(String code) {
    return 'Code $code applied';
  }

  @override
  String get proceedToCheckout => 'Proceed to checkout';

  @override
  String get itemUnavailable => 'Not available at this branch';

  @override
  String itemInsufficientStock(int count) {
    return 'Only $count available — please reduce quantity';
  }

  @override
  String get itemInactive => 'No longer available';

  @override
  String priceChangedFrom(String price) {
    return 'Price changed from $price';
  }

  @override
  String get removeItemConfirm => 'Remove this item from your cart?';

  @override
  String get cartHasIssues => 'Some items need your attention before checkout.';

  @override
  String get totalsCalculatedAtCheckout => 'Delivery and final totals are confirmed at checkout.';

  @override
  String get checkoutTitle => 'Checkout';

  @override
  String get fulfillmentMethod => 'How would you like to receive it?';

  @override
  String get delivery => 'Delivery';

  @override
  String get pickup => 'Pickup';

  @override
  String get deliveryAddress => 'Delivery address';

  @override
  String get addAddress => 'Add address';

  @override
  String get pickupBranch => 'Pickup showroom';

  @override
  String get pickupSlot => 'Pickup time';

  @override
  String get noSlots => 'No pickup times on this day.';

  @override
  String get slotFull => 'Full';

  @override
  String slotRemaining(int count) {
    return '$count left';
  }

  @override
  String get paymentMethod => 'Payment';

  @override
  String get paySadad => 'SADAD online payment';

  @override
  String get payCod => 'Cash on delivery';

  @override
  String get payAtPickup => 'Pay at pickup';

  @override
  String get paymentUnavailable => 'Not available for this order';

  @override
  String get paymentReasonCodLimit => 'Order exceeds the cash-on-delivery limit';

  @override
  String get loyaltyPoints => 'Loyalty points';

  @override
  String get usePoints => 'Redeem points';

  @override
  String pointsBalance(int points) {
    return 'Balance: $points pts';
  }

  @override
  String maxRedeemable(int points) {
    return 'Up to $points pts on this order';
  }

  @override
  String pointsToEarn(int points) {
    return 'You’ll earn $points pts';
  }

  @override
  String get orderSummary => 'Order summary';

  @override
  String get discount => 'Discount';

  @override
  String get deliveryFee => 'Delivery';

  @override
  String get serviceFee => 'Service fee';

  @override
  String get codFee => 'Cash on delivery fee';

  @override
  String get tax => 'Tax';

  @override
  String get loyaltyDiscount => 'Points redeemed';

  @override
  String get grandTotal => 'Total';

  @override
  String splitFulfillmentTitle(int count) {
    return 'Your order arrives in $count parts';
  }

  @override
  String get splitFulfillmentMessage => 'Items are prepared at different showrooms to get everything to you sooner.';

  @override
  String fulfilledFrom(String branch) {
    return 'From $branch';
  }

  @override
  String etaMinutes(String time) {
    return 'Estimated $time';
  }

  @override
  String hoursShort(int hours) {
    return '$hours h';
  }

  @override
  String minutesShort(int minutes) {
    return '$minutes min';
  }

  @override
  String get allowSplit => 'Allow split shipments';

  @override
  String get allowSplitHint => 'Receive items from multiple showrooms';

  @override
  String get customerNotes => 'Notes for our team';

  @override
  String get placeOrder => 'Place order';

  @override
  String get quoteChangedTitle => 'Your totals were updated';

  @override
  String get quoteChangedMessage =>
      'Prices, stock or fees changed since you started checkout. Please review the new totals.';

  @override
  String get reviewAndConfirm => 'Review & confirm';

  @override
  String previousTotal(String amount) {
    return 'Previously $amount';
  }

  @override
  String get cannotPlaceOrder => 'This order can’t be placed yet';

  @override
  String get orderPlaced => 'Thank you!';

  @override
  String orderPlacedMessage(String number) {
    return 'Order $number is confirmed.';
  }

  @override
  String get viewOrder => 'View order';

  @override
  String get continueShopping => 'Continue shopping';

  @override
  String get selectAddressFirst => 'Choose a delivery address';

  @override
  String get selectBranchFirst => 'Choose a pickup showroom';

  @override
  String get selectPaymentFirst => 'Choose a payment method';

  @override
  String get calculating => 'Calculating…';

  @override
  String get free => 'Free';

  @override
  String get deliveryDate => 'Delivery date';

  @override
  String get paymentTitle => 'Payment';

  @override
  String get paymentPending => 'Waiting for payment confirmation…';

  @override
  String get paymentPendingHint => 'This can take a moment. Please don’t close the app.';

  @override
  String get paymentPaid => 'Payment received';

  @override
  String get paymentFailed => 'Payment was not completed';

  @override
  String get paymentExpired => 'Payment session expired';

  @override
  String get paymentCancelled => 'Payment cancelled';

  @override
  String get paymentStillPending => 'We haven’t received confirmation yet. You can check again or view your order.';

  @override
  String get checkAgain => 'Check again';

  @override
  String get retryPayment => 'Try payment again';

  @override
  String get payNow => 'Pay now';

  @override
  String get openingPayment => 'Opening secure SADAD payment…';

  @override
  String get ordersTitle => 'My orders';

  @override
  String get noOrders => 'No orders yet';

  @override
  String get noOrdersMessage => 'When you place an order, you can follow it here.';

  @override
  String orderNumber(String number) {
    return 'Order $number';
  }

  @override
  String placedOn(String date) {
    return 'Placed $date';
  }

  @override
  String get orderItems => 'Items';

  @override
  String get orderTimeline => 'Progress';

  @override
  String get cancelOrder => 'Cancel order';

  @override
  String get cancelOrderTitle => 'Cancel this order?';

  @override
  String get cancelReason => 'Reason';

  @override
  String get cancelReasonChangedMind => 'I changed my mind';

  @override
  String get cancelReasonMistake => 'Ordered by mistake';

  @override
  String get cancelReasonDelay => 'Delivery takes too long';

  @override
  String get cancelReasonPrice => 'Found a better price';

  @override
  String get cancelReasonOther => 'Other';

  @override
  String get cancelDetails => 'Tell us more (optional)';

  @override
  String get cancelRequested => 'Cancellation requested — we’ll confirm shortly.';

  @override
  String get cancelApproved => 'Your order has been cancelled.';

  @override
  String get keepOrder => 'Keep order';

  @override
  String get reorder => 'Order again';

  @override
  String get reorderDone => 'Available items were added to your cart.';

  @override
  String reorderSkipped(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count items are no longer available.',
      one: '1 item is no longer available.',
    );
    return '$_temp0';
  }

  @override
  String get downloadInvoice => 'Invoice (PDF)';

  @override
  String get pickupPass => 'Pickup pass';

  @override
  String get pickupPassTitle => 'Your pickup pass';

  @override
  String get pickupPassInstructions => 'Show this code at the showroom counter to collect your order.';

  @override
  String passExpires(String time) {
    return 'Valid until $time';
  }

  @override
  String get trackDelivery => 'Delivery tracking';

  @override
  String get driver => 'Driver';

  @override
  String get trackingNumber => 'Tracking number';

  @override
  String get deliveryProvider => 'Carrier';

  @override
  String get eta => 'Estimated arrival';

  @override
  String shipment(String number) {
    return 'Shipment $number';
  }

  @override
  String get shippingTo => 'Delivering to';

  @override
  String get paymentLabel => 'Payment';

  @override
  String get filterAll => 'All';

  @override
  String get filterActive => 'Active';

  @override
  String get filterCompleted => 'Completed';

  @override
  String get filterCancelled => 'Cancelled';

  @override
  String get contactSupport => 'Get help with this order';

  @override
  String get requestReturn => 'Request a return';

  @override
  String get returnReasonDamaged => 'Arrived damaged';

  @override
  String get returnReasonWrong => 'Wrong item';

  @override
  String get returnReasonNotAsDescribed => 'Not as described';

  @override
  String get returnRequested => 'Return requested. Our team will contact you.';

  @override
  String get selectItemsToReturn => 'Select items to return';

  @override
  String slotLabel(String date, String from, String to) {
    return '$date, $from–$to';
  }

  @override
  String get wishlistTitle => 'Wishlist';

  @override
  String get wishlistEmpty => 'Nothing saved yet';

  @override
  String get wishlistEmptyMessage => 'Tap the heart on any piece to keep it here.';

  @override
  String get addedToWishlist => 'Saved to your wishlist';

  @override
  String get removedFromWishlist => 'Removed from your wishlist';

  @override
  String get auctionsTitle => 'Auctions';

  @override
  String get auctionUpcoming => 'Upcoming';

  @override
  String get auctionLive => 'Live';

  @override
  String get auctionCompleted => 'Ended';

  @override
  String get noAuctions => 'No auctions here right now';

  @override
  String get noAuctionsMessage => 'Turn on auction notifications to hear about the next one.';

  @override
  String lotsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(count, locale: localeName, other: '$count lots', one: '1 lot');
    return '$_temp0';
  }

  @override
  String lotNumber(int number) {
    return 'Lot $number';
  }

  @override
  String get currentBid => 'Current bid';

  @override
  String get startingPrice => 'Starting price';

  @override
  String get minNextBid => 'Minimum next bid';

  @override
  String get minIncrement => 'Increment';

  @override
  String get buyNowPrice => 'Buy now';

  @override
  String get reserveMet => 'Reserve met';

  @override
  String get reserveNotMet => 'Reserve not met';

  @override
  String bidCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count bids',
      one: '1 bid',
      zero: 'No bids',
    );
    return '$_temp0';
  }

  @override
  String get placeBid => 'Place bid';

  @override
  String get bidAmount => 'Your bid (QAR)';

  @override
  String get bidPlaced => 'Bid placed';

  @override
  String bidTooLow(String amount) {
    return 'Bid at least $amount';
  }

  @override
  String get youAreLeading => 'You’re the highest bidder';

  @override
  String get youWereOutbid => 'You’ve been outbid';

  @override
  String get youWon => 'You won this lot';

  @override
  String get youLost => 'This lot went to another bidder';

  @override
  String get autoBid => 'Auto-bid';

  @override
  String get autoBidHint => 'We’ll bid for you, one increment at a time, up to your maximum.';

  @override
  String get autoBidMax => 'Maximum amount (QAR)';

  @override
  String autoBidActive(String amount) {
    return 'Auto-bid up to $amount';
  }

  @override
  String get setAutoBid => 'Set auto-bid';

  @override
  String get cancelAutoBid => 'Stop auto-bid';

  @override
  String get watch => 'Watch';

  @override
  String get watching => 'Watching';

  @override
  String get registerToBid => 'Register to bid';

  @override
  String get acceptTerms => 'I have read and accept the auction terms';

  @override
  String get auctionTerms => 'Auction terms';

  @override
  String registeredPaddle(String paddle) {
    return 'Registered · Paddle $paddle';
  }

  @override
  String get registrationPending => 'Registration pending approval';

  @override
  String depositRequired(String amount) {
    return 'A refundable deposit of $amount is required';
  }

  @override
  String get payDeposit => 'Pay deposit';

  @override
  String get bidHistory => 'Bid history';

  @override
  String get noBids => 'No bids yet — be the first.';

  @override
  String get lotExtended => 'Time extended';

  @override
  String get lotEnded => 'Bidding has ended';

  @override
  String get lotNotStarted => 'Bidding hasn’t started';

  @override
  String get lotStatusScheduled => 'Scheduled';

  @override
  String get lotStatusOpen => 'Open';

  @override
  String get lotStatusClosing => 'Closing';

  @override
  String get lotStatusClosed => 'Closed';

  @override
  String get lotStatusSold => 'Sold';

  @override
  String get lotStatusUnsold => 'Unsold';

  @override
  String get lotStatusCancelled => 'Cancelled';

  @override
  String get myAuctions => 'My auctions';

  @override
  String get myBids => 'Bids';

  @override
  String get myWatchlist => 'Watchlist';

  @override
  String get myWins => 'Wins';

  @override
  String get checkoutWin => 'Complete purchase';

  @override
  String get winCheckoutTitle => 'Complete your win';

  @override
  String get realtimeLive => 'Live';

  @override
  String get realtimeReconnecting => 'Reconnecting…';

  @override
  String get confirmBidTitle => 'Confirm your bid';

  @override
  String confirmBidMessage(String amount) {
    return 'Place a bid of $amount? Bids are binding.';
  }

  @override
  String get lots => 'Lots';

  @override
  String get viewLot => 'View lot';

  @override
  String auctionStartsAt(String date) {
    return 'Starts $date';
  }

  @override
  String auctionEndedAt(String date) {
    return 'Ended $date';
  }

  @override
  String get winningBid => 'Winning bid';

  @override
  String payBy(String date) {
    return 'Pay by $date';
  }

  @override
  String get watchAdded => 'Added to your watchlist';

  @override
  String get watchRemoved => 'Removed from your watchlist';

  @override
  String get notificationsTitle => 'Notifications';

  @override
  String get noNotifications => 'You’re all caught up';

  @override
  String get noNotificationsMessage => 'Order updates and auction alerts will appear here.';

  @override
  String get markAllRead => 'Mark all read';

  @override
  String get accountTitle => 'Account';

  @override
  String get guestAccountTitle => 'Make it yours';

  @override
  String get guestAccountMessage => 'Sign in to track orders, save favourites and join auctions.';

  @override
  String get myOrders => 'Orders';

  @override
  String get myAddresses => 'Addresses';

  @override
  String get myWishlist => 'Wishlist';

  @override
  String get loyaltyAndReferral => 'Rewards & referrals';

  @override
  String get notificationPreferences => 'Notification preferences';

  @override
  String get support => 'Help & support';

  @override
  String get settings => 'Settings';

  @override
  String get editProfile => 'Edit profile';

  @override
  String get profileSaved => 'Profile updated';

  @override
  String get dateOfBirth => 'Date of birth';

  @override
  String get gender => 'Gender';

  @override
  String get male => 'Male';

  @override
  String get female => 'Female';

  @override
  String get notSpecified => 'Not specified';

  @override
  String get changePassword => 'Change password';

  @override
  String get setPassword => 'Set a password';

  @override
  String get currentPassword => 'Current password';

  @override
  String get passwordChanged => 'Password updated';

  @override
  String memberSince(String date) {
    return 'Member since $date';
  }

  @override
  String get addressesTitle => 'Saved addresses';

  @override
  String get noAddresses => 'No saved addresses';

  @override
  String get noAddressesMessage => 'Add an address for faster checkout.';

  @override
  String get newAddress => 'New address';

  @override
  String get editAddress => 'Edit address';

  @override
  String get addressLabel => 'Label';

  @override
  String get addressLabelHint => 'Home, Office…';

  @override
  String get recipientName => 'Recipient name';

  @override
  String get recipientPhone => 'Recipient mobile';

  @override
  String get deliveryZone => 'Delivery zone';

  @override
  String get zoneNumber => 'Zone';

  @override
  String get streetNumber => 'Street';

  @override
  String get buildingNumber => 'Building';

  @override
  String get unitNumber => 'Unit / flat';

  @override
  String get area => 'Area';

  @override
  String get city => 'City';

  @override
  String get landmark => 'Landmark';

  @override
  String get instructions => 'Delivery instructions';

  @override
  String get pinOnMap => 'Pin location on map';

  @override
  String get pinSet => 'Location pinned';

  @override
  String get moveMapToPin => 'Move the map to place the pin on your door';

  @override
  String get confirmLocation => 'Confirm location';

  @override
  String get setAsDefault => 'Set as default';

  @override
  String get defaultAddress => 'Default';

  @override
  String get deleteAddressConfirm => 'Delete this address?';

  @override
  String get addressSaved => 'Address saved';

  @override
  String get addressRequirement => 'Enter zone, street and building numbers — or pin your location on the map.';

  @override
  String get useCurrentLocation => 'Use current location';

  @override
  String get saveAddress => 'Save address';

  @override
  String get qatarAddressHint => 'Find these numbers on the blue plate by your door.';

  @override
  String get channelPush => 'Push';

  @override
  String get channelSms => 'SMS';

  @override
  String get channelWhatsapp => 'WhatsApp';

  @override
  String get channelEmail => 'Email';

  @override
  String get categoryTransactional => 'Orders & account';

  @override
  String get categoryTransactionalDesc => 'Order, payment, delivery and security updates';

  @override
  String get categoryMarketing => 'Offers & inspiration';

  @override
  String get categoryMarketingDesc => 'New collections, promotions and events';

  @override
  String get categoryAuction => 'Auctions';

  @override
  String get categoryAuctionDesc => 'Outbid alerts, wins and upcoming auctions';

  @override
  String get prefsSaved => 'Preferences saved';

  @override
  String get transactionalNote => 'Some essential order messages are always sent.';

  @override
  String get loyaltyTitle => 'Rewards';

  @override
  String pointsUnit(int points) {
    return '$points pts';
  }

  @override
  String tierLabel(String tier) {
    return '$tier member';
  }

  @override
  String pointsWorth(String value) {
    return 'Worth $value';
  }

  @override
  String lifetimeEarned(int points) {
    return 'Lifetime earned: $points pts';
  }

  @override
  String get loyaltyHistory => 'Points activity';

  @override
  String get noLoyaltyHistory => 'Earn points with every purchase.';

  @override
  String pointsExpire(String date) {
    return 'Expires $date';
  }

  @override
  String get referralTitle => 'Invite friends';

  @override
  String get referralBody => 'Share your code. When friends shop, you both earn rewards.';

  @override
  String get yourCode => 'Your code';

  @override
  String get shareReferral => 'Share invite';

  @override
  String referralShareText(String code, String url) {
    return 'Discover Al Bahar Home & Living. Use my code $code: $url';
  }

  @override
  String get referredCount => 'Friends joined';

  @override
  String get rewardedCount => 'Rewards earned';

  @override
  String get writeReviewTitle => 'Your review';

  @override
  String get yourRating => 'How would you rate it?';

  @override
  String get reviewTitleLabel => 'Headline';

  @override
  String get reviewBodyLabel => 'Tell others about it';

  @override
  String get addPhotos => 'Add photos';

  @override
  String maxPhotos(int count) {
    return 'Up to $count photos';
  }

  @override
  String get submitReview => 'Submit review';

  @override
  String get reviewSubmitted => 'Thank you! Your review will appear once approved.';

  @override
  String get ratingRequired => 'Please choose a rating.';

  @override
  String get removePhoto => 'Remove photo';

  @override
  String get fromCamera => 'Camera';

  @override
  String get fromGallery => 'Photo library';

  @override
  String starLabel(int count) {
    return '$count stars';
  }

  @override
  String get supportTitle => 'Help & support';

  @override
  String get newTicket => 'New request';

  @override
  String get noTickets => 'No support requests';

  @override
  String get noTicketsMessage => 'Need a hand? Our team replies in Arabic and English.';

  @override
  String get ticketCategory => 'Topic';

  @override
  String get ticketCategoryOrder => 'An order';

  @override
  String get ticketCategoryPayment => 'Payment or refund';

  @override
  String get ticketCategoryDelivery => 'Delivery or pickup';

  @override
  String get ticketCategoryProduct => 'A product';

  @override
  String get ticketCategoryAuction => 'Auctions';

  @override
  String get ticketCategoryAccount => 'My account';

  @override
  String get ticketCategoryOther => 'Something else';

  @override
  String get ticketSubject => 'Subject';

  @override
  String get ticketMessage => 'How can we help?';

  @override
  String get relatedOrder => 'Related order';

  @override
  String get noRelatedOrder => 'None';

  @override
  String get ticketCreated => 'Request sent. We’ll reply here.';

  @override
  String get typeMessage => 'Write a message…';

  @override
  String get send => 'Send';

  @override
  String get ticketStatusOpen => 'Open';

  @override
  String get ticketStatusPending => 'Awaiting reply';

  @override
  String get ticketStatusResolved => 'Resolved';

  @override
  String get ticketStatusClosed => 'Closed';

  @override
  String get supportTeam => 'Al Bahar support';

  @override
  String get you => 'You';

  @override
  String get settingsTitle => 'Settings';

  @override
  String get language => 'Language';

  @override
  String get appearance => 'Appearance';

  @override
  String get themeSystem => 'System';

  @override
  String get themeLight => 'Light';

  @override
  String get themeDark => 'Dark';

  @override
  String get legal => 'About & legal';

  @override
  String get termsOfService => 'Terms of service';

  @override
  String get privacyPolicy => 'Privacy policy';

  @override
  String get aboutUs => 'About Al Bahar';

  @override
  String get faq => 'FAQ';

  @override
  String get returnsPolicy => 'Returns policy';

  @override
  String get contactUs => 'Contact us';

  @override
  String appVersion(String version) {
    return 'Version $version';
  }

  @override
  String get privacy => 'Privacy';

  @override
  String get deleteAccount => 'Delete account';

  @override
  String get deleteAccountTitle => 'Delete your account?';

  @override
  String get deleteAccountWarning =>
      'Your account will be scheduled for deletion after a 14-day grace period. Your personal data will then be anonymised; order and invoice records required by law are retained. You can cancel any time before then.';

  @override
  String get deleteAccountReason => 'Why are you leaving? (optional)';

  @override
  String deleteAccountTypeToConfirm(String word) {
    return 'Type $word to confirm';
  }

  @override
  String get deleteWord => 'DELETE';

  @override
  String deleteAccountRequested(String date) {
    return 'Deletion scheduled for $date';
  }

  @override
  String get cancelDeletion => 'Cancel deletion';

  @override
  String get deletionCancelled => 'Your account will not be deleted.';

  @override
  String get logoutConfirm => 'Sign out of Al Bahar on this device?';

  @override
  String get logoutAllDevices => 'Sign out of all devices';

  @override
  String get marketingConsentToggle => 'Marketing messages';

  @override
  String get paymentStatusPending => 'Pending';

  @override
  String get paymentStatusPaid => 'Paid';

  @override
  String get paymentStatusFailed => 'Failed';

  @override
  String get paymentStatusRefunded => 'Refunded';

  @override
  String paymentMethodLabel(String method) {
    return '$method';
  }

  @override
  String get deliverySlotTitle => 'Delivery time';

  @override
  String get deliverySlotMoreDates => 'More dates';

  @override
  String get deliverySlotNoDates => 'No delivery times in the next 7 days. Try “More dates”.';

  @override
  String get deliverySlotNoSlotsDay => 'No delivery times on this day.';

  @override
  String get deliverySlotRequiredHint => 'Choose a delivery time to place your order';

  @override
  String get deliverySlotRequiredError => 'Please choose a delivery time for this order.';

  @override
  String get deliverySlotUnavailableError => 'That delivery time is no longer available. Please choose another.';

  @override
  String deliverySlotSummary(String date, String from, String to) {
    return 'Delivery: $date, $from – $to';
  }

  @override
  String get coloursTitle => 'Colours';

  @override
  String get coloursHint => 'Choose how many of each colour';

  @override
  String addItemsToBag(int count) {
    return 'Add $count to bag';
  }

  @override
  String get colourOutOfStock => 'Out of stock';

  @override
  String get pickAtLeastOne => 'Pick a quantity for at least one colour';

  @override
  String didYouMean(String term) {
    return 'Did you mean “$term”?';
  }

  @override
  String searchSeeAll(String term) {
    return 'See all results for “$term”';
  }

  @override
  String get buyAgainTitle => 'Buy again';

  @override
  String get buyAgainAdd => 'Add';

  @override
  String buyAgainBought(int count) {
    return 'Bought $count times';
  }

  @override
  String get chooseOptions => 'Choose options';

  @override
  String get orderStepPlaced => 'Placed';

  @override
  String get orderStepPreparing => 'Preparing';

  @override
  String get orderStepReady => 'Ready';

  @override
  String get orderStepOnTheWay => 'On the way';

  @override
  String get orderStepDone => 'Done';

  @override
  String get orderLivePendingPayment => 'Waiting for payment';

  @override
  String get orderLiveConfirmed => 'Order confirmed';

  @override
  String get orderLivePreparing => 'We are preparing your order';

  @override
  String get orderLiveOnTheWay => 'Your order is on the way';

  @override
  String get orderLiveReady => 'Ready for pickup';

  @override
  String orderNumberLabel(String number) {
    return 'Order $number';
  }

  @override
  String orderMoreCount(int count) {
    return '+$count more';
  }

  @override
  String get trackOrder => 'Track order';

  @override
  String get trackTitle => 'Live tracking';

  @override
  String get trackDriverOnWay => 'Your driver is on the way';

  @override
  String get trackWaiting => 'Your driver will appear here once the order is picked up';

  @override
  String get trackYou => 'Delivery address';

  @override
  String get trackBranch => 'Branch';

  @override
  String get trackUpdated => 'Updated live';
}
