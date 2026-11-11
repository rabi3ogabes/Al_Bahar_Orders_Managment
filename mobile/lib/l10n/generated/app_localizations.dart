import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_ar.dart';
import 'app_localizations_en.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppLocalizations
/// returned by `AppLocalizations.of(context)`.
///
/// Applications need to include `AppLocalizations.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'generated/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppLocalizations.localizationsDelegates,
///   supportedLocales: AppLocalizations.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppLocalizations.supportedLocales
/// property.
abstract class AppLocalizations {
  AppLocalizations(String locale) : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppLocalizations of(BuildContext context) {
    return Localizations.of<AppLocalizations>(context, AppLocalizations)!;
  }

  static const LocalizationsDelegate<AppLocalizations> delegate = _AppLocalizationsDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates = <LocalizationsDelegate<dynamic>>[
    delegate,
    GlobalMaterialLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
  ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[Locale('ar'), Locale('en')];

  /// No description provided for @appName.
  ///
  /// In en, this message translates to:
  /// **'Al Bahar'**
  String get appName;

  /// No description provided for @appFullName.
  ///
  /// In en, this message translates to:
  /// **'Al Bahar Home & Living'**
  String get appFullName;

  /// No description provided for @appTagline.
  ///
  /// In en, this message translates to:
  /// **'Considered pieces for a beautiful home'**
  String get appTagline;

  /// No description provided for @retry.
  ///
  /// In en, this message translates to:
  /// **'Try again'**
  String get retry;

  /// No description provided for @cancel.
  ///
  /// In en, this message translates to:
  /// **'Cancel'**
  String get cancel;

  /// No description provided for @confirm.
  ///
  /// In en, this message translates to:
  /// **'Confirm'**
  String get confirm;

  /// No description provided for @save.
  ///
  /// In en, this message translates to:
  /// **'Save'**
  String get save;

  /// No description provided for @delete.
  ///
  /// In en, this message translates to:
  /// **'Delete'**
  String get delete;

  /// No description provided for @edit.
  ///
  /// In en, this message translates to:
  /// **'Edit'**
  String get edit;

  /// No description provided for @close.
  ///
  /// In en, this message translates to:
  /// **'Close'**
  String get close;

  /// No description provided for @done.
  ///
  /// In en, this message translates to:
  /// **'Done'**
  String get done;

  /// No description provided for @next.
  ///
  /// In en, this message translates to:
  /// **'Next'**
  String get next;

  /// No description provided for @skip.
  ///
  /// In en, this message translates to:
  /// **'Skip'**
  String get skip;

  /// No description provided for @continueLabel.
  ///
  /// In en, this message translates to:
  /// **'Continue'**
  String get continueLabel;

  /// No description provided for @apply.
  ///
  /// In en, this message translates to:
  /// **'Apply'**
  String get apply;

  /// No description provided for @reset.
  ///
  /// In en, this message translates to:
  /// **'Reset'**
  String get reset;

  /// No description provided for @clear.
  ///
  /// In en, this message translates to:
  /// **'Clear'**
  String get clear;

  /// No description provided for @seeAll.
  ///
  /// In en, this message translates to:
  /// **'See all'**
  String get seeAll;

  /// No description provided for @loading.
  ///
  /// In en, this message translates to:
  /// **'Loading…'**
  String get loading;

  /// No description provided for @ok.
  ///
  /// In en, this message translates to:
  /// **'OK'**
  String get ok;

  /// No description provided for @share.
  ///
  /// In en, this message translates to:
  /// **'Share'**
  String get share;

  /// No description provided for @copy.
  ///
  /// In en, this message translates to:
  /// **'Copy'**
  String get copy;

  /// No description provided for @copied.
  ///
  /// In en, this message translates to:
  /// **'Copied to clipboard'**
  String get copied;

  /// No description provided for @search.
  ///
  /// In en, this message translates to:
  /// **'Search'**
  String get search;

  /// No description provided for @optional.
  ///
  /// In en, this message translates to:
  /// **'Optional'**
  String get optional;

  /// No description provided for @fieldRequired.
  ///
  /// In en, this message translates to:
  /// **'This field is required'**
  String get fieldRequired;

  /// No description provided for @add.
  ///
  /// In en, this message translates to:
  /// **'Add'**
  String get add;

  /// No description provided for @remove.
  ///
  /// In en, this message translates to:
  /// **'Remove'**
  String get remove;

  /// No description provided for @change.
  ///
  /// In en, this message translates to:
  /// **'Change'**
  String get change;

  /// No description provided for @call.
  ///
  /// In en, this message translates to:
  /// **'Call'**
  String get call;

  /// No description provided for @directions.
  ///
  /// In en, this message translates to:
  /// **'Directions'**
  String get directions;

  /// No description provided for @signIn.
  ///
  /// In en, this message translates to:
  /// **'Sign in'**
  String get signIn;

  /// No description provided for @signOut.
  ///
  /// In en, this message translates to:
  /// **'Sign out'**
  String get signOut;

  /// No description provided for @signInRequiredTitle.
  ///
  /// In en, this message translates to:
  /// **'Sign in to continue'**
  String get signInRequiredTitle;

  /// No description provided for @signInRequiredMessage.
  ///
  /// In en, this message translates to:
  /// **'Your cart, orders and wishlist are saved to your account.'**
  String get signInRequiredMessage;

  /// No description provided for @back.
  ///
  /// In en, this message translates to:
  /// **'Back'**
  String get back;

  /// No description provided for @menu.
  ///
  /// In en, this message translates to:
  /// **'More options'**
  String get menu;

  /// No description provided for @errorTitle.
  ///
  /// In en, this message translates to:
  /// **'Something went wrong'**
  String get errorTitle;

  /// No description provided for @errorGeneric.
  ///
  /// In en, this message translates to:
  /// **'We couldn’t complete that. Please try again.'**
  String get errorGeneric;

  /// No description provided for @errorOffline.
  ///
  /// In en, this message translates to:
  /// **'You appear to be offline. Check your connection and try again.'**
  String get errorOffline;

  /// No description provided for @errorTimeout.
  ///
  /// In en, this message translates to:
  /// **'The connection is taking too long. Please try again.'**
  String get errorTimeout;

  /// No description provided for @errorServer.
  ///
  /// In en, this message translates to:
  /// **'Our service is having a moment. Please try again shortly.'**
  String get errorServer;

  /// No description provided for @errorNotFound.
  ///
  /// In en, this message translates to:
  /// **'We couldn’t find what you were looking for.'**
  String get errorNotFound;

  /// No description provided for @errorForbidden.
  ///
  /// In en, this message translates to:
  /// **'You don’t have access to this.'**
  String get errorForbidden;

  /// No description provided for @errorRateLimited.
  ///
  /// In en, this message translates to:
  /// **'Too many attempts. Please wait {seconds} seconds.'**
  String errorRateLimited(int seconds);

  /// No description provided for @errorSessionExpired.
  ///
  /// In en, this message translates to:
  /// **'Your session has ended. Please sign in again.'**
  String get errorSessionExpired;

  /// No description provided for @offlineBanner.
  ///
  /// In en, this message translates to:
  /// **'Offline — showing what we can'**
  String get offlineBanner;

  /// No description provided for @notFoundTitle.
  ///
  /// In en, this message translates to:
  /// **'Not found'**
  String get notFoundTitle;

  /// No description provided for @requestReference.
  ///
  /// In en, this message translates to:
  /// **'Reference: {id}'**
  String requestReference(String id);

  /// No description provided for @navHome.
  ///
  /// In en, this message translates to:
  /// **'Home'**
  String get navHome;

  /// No description provided for @navCategories.
  ///
  /// In en, this message translates to:
  /// **'Shop'**
  String get navCategories;

  /// No description provided for @navAuctions.
  ///
  /// In en, this message translates to:
  /// **'Auctions'**
  String get navAuctions;

  /// No description provided for @navCart.
  ///
  /// In en, this message translates to:
  /// **'Cart'**
  String get navCart;

  /// No description provided for @navAccount.
  ///
  /// In en, this message translates to:
  /// **'Account'**
  String get navAccount;

  /// No description provided for @onboardingTitle1.
  ///
  /// In en, this message translates to:
  /// **'Living, beautifully considered'**
  String get onboardingTitle1;

  /// No description provided for @onboardingBody1.
  ///
  /// In en, this message translates to:
  /// **'Furniture, décor and essentials curated for homes across Qatar.'**
  String get onboardingBody1;

  /// No description provided for @onboardingTitle2.
  ///
  /// In en, this message translates to:
  /// **'Your branch, your price'**
  String get onboardingTitle2;

  /// No description provided for @onboardingBody2.
  ///
  /// In en, this message translates to:
  /// **'See live stock and prices at the showroom nearest you, then deliver or pick up.'**
  String get onboardingBody2;

  /// No description provided for @onboardingTitle3.
  ///
  /// In en, this message translates to:
  /// **'Bid on exceptional pieces'**
  String get onboardingTitle3;

  /// No description provided for @onboardingBody3.
  ///
  /// In en, this message translates to:
  /// **'Join live auctions for one-of-a-kind finds, with real-time bidding.'**
  String get onboardingBody3;

  /// No description provided for @getStarted.
  ///
  /// In en, this message translates to:
  /// **'Get started'**
  String get getStarted;

  /// No description provided for @chooseLanguageTitle.
  ///
  /// In en, this message translates to:
  /// **'Choose your language'**
  String get chooseLanguageTitle;

  /// No description provided for @chooseLanguageSubtitle.
  ///
  /// In en, this message translates to:
  /// **'You can change this any time in Settings.'**
  String get chooseLanguageSubtitle;

  /// No description provided for @languageArabic.
  ///
  /// In en, this message translates to:
  /// **'العربية'**
  String get languageArabic;

  /// No description provided for @languageEnglish.
  ///
  /// In en, this message translates to:
  /// **'English'**
  String get languageEnglish;

  /// No description provided for @loginTitle.
  ///
  /// In en, this message translates to:
  /// **'Welcome'**
  String get loginTitle;

  /// No description provided for @loginSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Enter your Qatar mobile number and we’ll text you a verification code.'**
  String get loginSubtitle;

  /// No description provided for @phoneLabel.
  ///
  /// In en, this message translates to:
  /// **'Mobile number'**
  String get phoneLabel;

  /// No description provided for @phoneHint.
  ///
  /// In en, this message translates to:
  /// **'5512 3456'**
  String get phoneHint;

  /// No description provided for @phoneInvalid.
  ///
  /// In en, this message translates to:
  /// **'Please enter a valid Qatar mobile number.'**
  String get phoneInvalid;

  /// No description provided for @sendCode.
  ///
  /// In en, this message translates to:
  /// **'Send code'**
  String get sendCode;

  /// No description provided for @otpTitle.
  ///
  /// In en, this message translates to:
  /// **'Verify your number'**
  String get otpTitle;

  /// No description provided for @otpSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Enter the 6-digit code sent to {phone}'**
  String otpSubtitle(String phone);

  /// No description provided for @otpCodeLabel.
  ///
  /// In en, this message translates to:
  /// **'Verification code'**
  String get otpCodeLabel;

  /// No description provided for @verify.
  ///
  /// In en, this message translates to:
  /// **'Verify'**
  String get verify;

  /// No description provided for @resendCode.
  ///
  /// In en, this message translates to:
  /// **'Resend code'**
  String get resendCode;

  /// No description provided for @resendIn.
  ///
  /// In en, this message translates to:
  /// **'Resend in {seconds}s'**
  String resendIn(int seconds);

  /// No description provided for @changeNumber.
  ///
  /// In en, this message translates to:
  /// **'Change number'**
  String get changeNumber;

  /// No description provided for @codeSent.
  ///
  /// In en, this message translates to:
  /// **'A new code is on its way.'**
  String get codeSent;

  /// No description provided for @registerTitle.
  ///
  /// In en, this message translates to:
  /// **'Create your account'**
  String get registerTitle;

  /// No description provided for @registerSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Just a few details to personalise your experience.'**
  String get registerSubtitle;

  /// No description provided for @nameLabel.
  ///
  /// In en, this message translates to:
  /// **'Full name'**
  String get nameLabel;

  /// No description provided for @emailLabel.
  ///
  /// In en, this message translates to:
  /// **'Email'**
  String get emailLabel;

  /// No description provided for @passwordLabel.
  ///
  /// In en, this message translates to:
  /// **'Password'**
  String get passwordLabel;

  /// No description provided for @passwordConfirmLabel.
  ///
  /// In en, this message translates to:
  /// **'Confirm password'**
  String get passwordConfirmLabel;

  /// No description provided for @passwordOptionalHint.
  ///
  /// In en, this message translates to:
  /// **'Optional — lets you sign in without a code'**
  String get passwordOptionalHint;

  /// No description provided for @referralCodeLabel.
  ///
  /// In en, this message translates to:
  /// **'Referral code'**
  String get referralCodeLabel;

  /// No description provided for @marketingConsent.
  ///
  /// In en, this message translates to:
  /// **'Send me offers, new collections and auction invitations'**
  String get marketingConsent;

  /// No description provided for @createAccount.
  ///
  /// In en, this message translates to:
  /// **'Create account'**
  String get createAccount;

  /// No description provided for @loginWithPassword.
  ///
  /// In en, this message translates to:
  /// **'Sign in with password'**
  String get loginWithPassword;

  /// No description provided for @loginWithOtp.
  ///
  /// In en, this message translates to:
  /// **'Sign in with a code instead'**
  String get loginWithOtp;

  /// No description provided for @emailOrPhoneLabel.
  ///
  /// In en, this message translates to:
  /// **'Mobile number or email'**
  String get emailOrPhoneLabel;

  /// No description provided for @forgotPassword.
  ///
  /// In en, this message translates to:
  /// **'Forgot password?'**
  String get forgotPassword;

  /// No description provided for @resetPasswordTitle.
  ///
  /// In en, this message translates to:
  /// **'Reset password'**
  String get resetPasswordTitle;

  /// No description provided for @resetPasswordSubtitle.
  ///
  /// In en, this message translates to:
  /// **'We’ll text a code to your mobile number to confirm it’s you.'**
  String get resetPasswordSubtitle;

  /// No description provided for @newPasswordLabel.
  ///
  /// In en, this message translates to:
  /// **'New password'**
  String get newPasswordLabel;

  /// No description provided for @resetPassword.
  ///
  /// In en, this message translates to:
  /// **'Reset password'**
  String get resetPassword;

  /// No description provided for @passwordResetSuccess.
  ///
  /// In en, this message translates to:
  /// **'Your password has been updated. Please sign in.'**
  String get passwordResetSuccess;

  /// No description provided for @passwordsDoNotMatch.
  ///
  /// In en, this message translates to:
  /// **'Passwords do not match.'**
  String get passwordsDoNotMatch;

  /// No description provided for @passwordTooShort.
  ///
  /// In en, this message translates to:
  /// **'Use at least 8 characters.'**
  String get passwordTooShort;

  /// No description provided for @termsAgreementPrefix.
  ///
  /// In en, this message translates to:
  /// **'By continuing you agree to our'**
  String get termsAgreementPrefix;

  /// No description provided for @termsAndPrivacy.
  ///
  /// In en, this message translates to:
  /// **'Terms & Privacy Policy'**
  String get termsAndPrivacy;

  /// No description provided for @continueAsGuest.
  ///
  /// In en, this message translates to:
  /// **'Browse as guest'**
  String get continueAsGuest;

  /// No description provided for @welcomeBack.
  ///
  /// In en, this message translates to:
  /// **'Welcome back, {name}'**
  String welcomeBack(String name);

  /// No description provided for @emailInvalid.
  ///
  /// In en, this message translates to:
  /// **'Please enter a valid email address.'**
  String get emailInvalid;

  /// No description provided for @debugCodeHint.
  ///
  /// In en, this message translates to:
  /// **'Dev code: {code}'**
  String debugCodeHint(String code);

  /// No description provided for @sendResetCode.
  ///
  /// In en, this message translates to:
  /// **'Send reset code'**
  String get sendResetCode;

  /// No description provided for @homeGreeting.
  ///
  /// In en, this message translates to:
  /// **'Good to see you'**
  String get homeGreeting;

  /// No description provided for @homeGreetingNamed.
  ///
  /// In en, this message translates to:
  /// **'Hello, {name}'**
  String homeGreetingNamed(String name);

  /// No description provided for @searchHint.
  ///
  /// In en, this message translates to:
  /// **'Search sofas, lighting, rugs…'**
  String get searchHint;

  /// No description provided for @homeEmpty.
  ///
  /// In en, this message translates to:
  /// **'Our new collection is being arranged. Check back soon.'**
  String get homeEmpty;

  /// No description provided for @endsIn.
  ///
  /// In en, this message translates to:
  /// **'Ends in {time}'**
  String endsIn(String time);

  /// No description provided for @startsIn.
  ///
  /// In en, this message translates to:
  /// **'Starts in {time}'**
  String startsIn(String time);

  /// No description provided for @preferredBranch.
  ///
  /// In en, this message translates to:
  /// **'Preferred branch'**
  String get preferredBranch;

  /// No description provided for @chooseBranch.
  ///
  /// In en, this message translates to:
  /// **'Choose a branch'**
  String get chooseBranch;

  /// No description provided for @shoppingAt.
  ///
  /// In en, this message translates to:
  /// **'Shopping at {branch}'**
  String shoppingAt(String branch);

  /// No description provided for @shopNow.
  ///
  /// In en, this message translates to:
  /// **'Shop now'**
  String get shopNow;

  /// No description provided for @countdownDays.
  ///
  /// In en, this message translates to:
  /// **'{days}d'**
  String countdownDays(int days);

  /// No description provided for @categoriesTitle.
  ///
  /// In en, this message translates to:
  /// **'Shop by category'**
  String get categoriesTitle;

  /// No description provided for @allInCategory.
  ///
  /// In en, this message translates to:
  /// **'All {category}'**
  String allInCategory(String category);

  /// No description provided for @productsTitle.
  ///
  /// In en, this message translates to:
  /// **'Products'**
  String get productsTitle;

  /// No description provided for @filters.
  ///
  /// In en, this message translates to:
  /// **'Filters'**
  String get filters;

  /// No description provided for @sortBy.
  ///
  /// In en, this message translates to:
  /// **'Sort by'**
  String get sortBy;

  /// No description provided for @sortRelevance.
  ///
  /// In en, this message translates to:
  /// **'Most relevant'**
  String get sortRelevance;

  /// No description provided for @sortPriceLowHigh.
  ///
  /// In en, this message translates to:
  /// **'Price: low to high'**
  String get sortPriceLowHigh;

  /// No description provided for @sortPriceHighLow.
  ///
  /// In en, this message translates to:
  /// **'Price: high to low'**
  String get sortPriceHighLow;

  /// No description provided for @sortNewest.
  ///
  /// In en, this message translates to:
  /// **'Newest'**
  String get sortNewest;

  /// No description provided for @sortRating.
  ///
  /// In en, this message translates to:
  /// **'Top rated'**
  String get sortRating;

  /// No description provided for @sortPopularity.
  ///
  /// In en, this message translates to:
  /// **'Most popular'**
  String get sortPopularity;

  /// No description provided for @priceRange.
  ///
  /// In en, this message translates to:
  /// **'Price (QAR)'**
  String get priceRange;

  /// No description provided for @minPrice.
  ///
  /// In en, this message translates to:
  /// **'Min'**
  String get minPrice;

  /// No description provided for @maxPrice.
  ///
  /// In en, this message translates to:
  /// **'Max'**
  String get maxPrice;

  /// No description provided for @inStockOnly.
  ///
  /// In en, this message translates to:
  /// **'In stock only'**
  String get inStockOnly;

  /// No description provided for @onSaleOnly.
  ///
  /// In en, this message translates to:
  /// **'On sale'**
  String get onSaleOnly;

  /// No description provided for @brandFilter.
  ///
  /// In en, this message translates to:
  /// **'Brand'**
  String get brandFilter;

  /// No description provided for @showResults.
  ///
  /// In en, this message translates to:
  /// **'Show results'**
  String get showResults;

  /// No description provided for @clearAll.
  ///
  /// In en, this message translates to:
  /// **'Clear all'**
  String get clearAll;

  /// No description provided for @noProducts.
  ///
  /// In en, this message translates to:
  /// **'Nothing here yet'**
  String get noProducts;

  /// No description provided for @noProductsMessage.
  ///
  /// In en, this message translates to:
  /// **'Try adjusting your filters or exploring another category.'**
  String get noProductsMessage;

  /// No description provided for @resultsCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =0{No results} =1{1 result} other{{count} results}}'**
  String resultsCount(int count);

  /// No description provided for @searchTitle.
  ///
  /// In en, this message translates to:
  /// **'Search'**
  String get searchTitle;

  /// No description provided for @recentSearches.
  ///
  /// In en, this message translates to:
  /// **'Recent searches'**
  String get recentSearches;

  /// No description provided for @clearRecent.
  ///
  /// In en, this message translates to:
  /// **'Clear'**
  String get clearRecent;

  /// No description provided for @searchPrompt.
  ///
  /// In en, this message translates to:
  /// **'Search in Arabic or English, by name, SKU or barcode.'**
  String get searchPrompt;

  /// No description provided for @searchNoResults.
  ///
  /// In en, this message translates to:
  /// **'No results for “{query}”'**
  String searchNoResults(String query);

  /// No description provided for @badgeNew.
  ///
  /// In en, this message translates to:
  /// **'New'**
  String get badgeNew;

  /// No description provided for @badgeSale.
  ///
  /// In en, this message translates to:
  /// **'Sale'**
  String get badgeSale;

  /// No description provided for @badgeBestseller.
  ///
  /// In en, this message translates to:
  /// **'Bestseller'**
  String get badgeBestseller;

  /// No description provided for @outOfStock.
  ///
  /// In en, this message translates to:
  /// **'Out of stock'**
  String get outOfStock;

  /// No description provided for @inStock.
  ///
  /// In en, this message translates to:
  /// **'In stock'**
  String get inStock;

  /// No description provided for @onlyLeft.
  ///
  /// In en, this message translates to:
  /// **'Only {count} left'**
  String onlyLeft(int count);

  /// No description provided for @discountOff.
  ///
  /// In en, this message translates to:
  /// **'-{percent}%'**
  String discountOff(int percent);

  /// No description provided for @subcategories.
  ///
  /// In en, this message translates to:
  /// **'Browse'**
  String get subcategories;

  /// No description provided for @addToCart.
  ///
  /// In en, this message translates to:
  /// **'Add to cart'**
  String get addToCart;

  /// No description provided for @addedToCart.
  ///
  /// In en, this message translates to:
  /// **'Added to your cart'**
  String get addedToCart;

  /// No description provided for @viewCart.
  ///
  /// In en, this message translates to:
  /// **'View cart'**
  String get viewCart;

  /// No description provided for @selectOption.
  ///
  /// In en, this message translates to:
  /// **'Select {attribute}'**
  String selectOption(String attribute);

  /// No description provided for @specifications.
  ///
  /// In en, this message translates to:
  /// **'Specifications'**
  String get specifications;

  /// No description provided for @dimensions.
  ///
  /// In en, this message translates to:
  /// **'Dimensions'**
  String get dimensions;

  /// No description provided for @dimensionLength.
  ///
  /// In en, this message translates to:
  /// **'Length'**
  String get dimensionLength;

  /// No description provided for @dimensionWidth.
  ///
  /// In en, this message translates to:
  /// **'Width'**
  String get dimensionWidth;

  /// No description provided for @dimensionHeight.
  ///
  /// In en, this message translates to:
  /// **'Height'**
  String get dimensionHeight;

  /// No description provided for @weight.
  ///
  /// In en, this message translates to:
  /// **'Weight'**
  String get weight;

  /// No description provided for @warranty.
  ///
  /// In en, this message translates to:
  /// **'Warranty'**
  String get warranty;

  /// No description provided for @warrantyMonths.
  ///
  /// In en, this message translates to:
  /// **'{months, plural, =1{1 month} other{{months} months}}'**
  String warrantyMonths(int months);

  /// No description provided for @warrantyBy.
  ///
  /// In en, this message translates to:
  /// **'Provided by {provider}'**
  String warrantyBy(String provider);

  /// No description provided for @assemblyRequired.
  ///
  /// In en, this message translates to:
  /// **'Assembly required'**
  String get assemblyRequired;

  /// No description provided for @assemblyAvailable.
  ///
  /// In en, this message translates to:
  /// **'Assembly service available'**
  String get assemblyAvailable;

  /// No description provided for @oversizedItem.
  ///
  /// In en, this message translates to:
  /// **'Large item — special delivery'**
  String get oversizedItem;

  /// No description provided for @description.
  ///
  /// In en, this message translates to:
  /// **'Description'**
  String get description;

  /// No description provided for @details.
  ///
  /// In en, this message translates to:
  /// **'Details'**
  String get details;

  /// No description provided for @reviews.
  ///
  /// In en, this message translates to:
  /// **'Reviews'**
  String get reviews;

  /// No description provided for @reviewsCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =0{No reviews} =1{1 review} other{{count} reviews}}'**
  String reviewsCount(int count);

  /// No description provided for @writeReview.
  ///
  /// In en, this message translates to:
  /// **'Write a review'**
  String get writeReview;

  /// No description provided for @noReviews.
  ///
  /// In en, this message translates to:
  /// **'Be the first to share your thoughts.'**
  String get noReviews;

  /// No description provided for @relatedProducts.
  ///
  /// In en, this message translates to:
  /// **'You may also like'**
  String get relatedProducts;

  /// No description provided for @availability.
  ///
  /// In en, this message translates to:
  /// **'Availability'**
  String get availability;

  /// No description provided for @availableAt.
  ///
  /// In en, this message translates to:
  /// **'Available at {branch}'**
  String availableAt(String branch);

  /// No description provided for @notInBranch.
  ///
  /// In en, this message translates to:
  /// **'Not sold at {branch}'**
  String notInBranch(String branch);

  /// No description provided for @outOfStockAt.
  ///
  /// In en, this message translates to:
  /// **'Out of stock at {branch}'**
  String outOfStockAt(String branch);

  /// No description provided for @checkOtherBranches.
  ///
  /// In en, this message translates to:
  /// **'Check other branches'**
  String get checkOtherBranches;

  /// No description provided for @branchAvailabilityTitle.
  ///
  /// In en, this message translates to:
  /// **'Stock by branch'**
  String get branchAvailabilityTitle;

  /// No description provided for @availableQty.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =0{None available} =1{1 available} other{{count}+ available}}'**
  String availableQty(int count);

  /// No description provided for @deliveryAvailable.
  ///
  /// In en, this message translates to:
  /// **'Home delivery'**
  String get deliveryAvailable;

  /// No description provided for @pickupAvailable.
  ///
  /// In en, this message translates to:
  /// **'Store pickup'**
  String get pickupAvailable;

  /// No description provided for @notifyBackInStock.
  ///
  /// In en, this message translates to:
  /// **'Notify me when available'**
  String get notifyBackInStock;

  /// No description provided for @alertCreated.
  ///
  /// In en, this message translates to:
  /// **'We’ll let you know.'**
  String get alertCreated;

  /// No description provided for @addToWishlist.
  ///
  /// In en, this message translates to:
  /// **'Add to wishlist'**
  String get addToWishlist;

  /// No description provided for @removeFromWishlist.
  ///
  /// In en, this message translates to:
  /// **'Remove from wishlist'**
  String get removeFromWishlist;

  /// No description provided for @saleEnds.
  ///
  /// In en, this message translates to:
  /// **'Offer ends {date}'**
  String saleEnds(String date);

  /// No description provided for @skuLabel.
  ///
  /// In en, this message translates to:
  /// **'SKU {sku}'**
  String skuLabel(String sku);

  /// No description provided for @quantity.
  ///
  /// In en, this message translates to:
  /// **'Quantity'**
  String get quantity;

  /// No description provided for @increaseQuantity.
  ///
  /// In en, this message translates to:
  /// **'Increase quantity'**
  String get increaseQuantity;

  /// No description provided for @decreaseQuantity.
  ///
  /// In en, this message translates to:
  /// **'Decrease quantity'**
  String get decreaseQuantity;

  /// No description provided for @ratingLabel.
  ///
  /// In en, this message translates to:
  /// **'Rated {rating} out of 5'**
  String ratingLabel(String rating);

  /// No description provided for @verifiedPurchase.
  ///
  /// In en, this message translates to:
  /// **'Verified purchase'**
  String get verifiedPurchase;

  /// No description provided for @reportReview.
  ///
  /// In en, this message translates to:
  /// **'Report'**
  String get reportReview;

  /// No description provided for @reviewReported.
  ///
  /// In en, this message translates to:
  /// **'Thanks — our team will review it.'**
  String get reviewReported;

  /// No description provided for @productUnavailable.
  ///
  /// In en, this message translates to:
  /// **'Currently unavailable'**
  String get productUnavailable;

  /// No description provided for @selectBranchToBuy.
  ///
  /// In en, this message translates to:
  /// **'Choose a branch to see price & stock'**
  String get selectBranchToBuy;

  /// No description provided for @imageOf.
  ///
  /// In en, this message translates to:
  /// **'Image {index} of {total}'**
  String imageOf(int index, int total);

  /// No description provided for @questions.
  ///
  /// In en, this message translates to:
  /// **'Questions & answers'**
  String get questions;

  /// No description provided for @askQuestion.
  ///
  /// In en, this message translates to:
  /// **'Ask a question'**
  String get askQuestion;

  /// No description provided for @questionSubmitted.
  ///
  /// In en, this message translates to:
  /// **'Your question was submitted.'**
  String get questionSubmitted;

  /// No description provided for @noQuestions.
  ///
  /// In en, this message translates to:
  /// **'No questions yet.'**
  String get noQuestions;

  /// No description provided for @branchesTitle.
  ///
  /// In en, this message translates to:
  /// **'Our showrooms'**
  String get branchesTitle;

  /// No description provided for @nearbyBranches.
  ///
  /// In en, this message translates to:
  /// **'Nearby'**
  String get nearbyBranches;

  /// No description provided for @allBranches.
  ///
  /// In en, this message translates to:
  /// **'All'**
  String get allBranches;

  /// No description provided for @useMyLocation.
  ///
  /// In en, this message translates to:
  /// **'Use my location'**
  String get useMyLocation;

  /// No description provided for @locationDenied.
  ///
  /// In en, this message translates to:
  /// **'Location is off'**
  String get locationDenied;

  /// No description provided for @locationDeniedMessage.
  ///
  /// In en, this message translates to:
  /// **'Allow location access to find showrooms near you, or choose one from the list.'**
  String get locationDeniedMessage;

  /// No description provided for @openSettings.
  ///
  /// In en, this message translates to:
  /// **'Open settings'**
  String get openSettings;

  /// No description provided for @mapView.
  ///
  /// In en, this message translates to:
  /// **'Map'**
  String get mapView;

  /// No description provided for @listView.
  ///
  /// In en, this message translates to:
  /// **'List'**
  String get listView;

  /// No description provided for @openNow.
  ///
  /// In en, this message translates to:
  /// **'Open now'**
  String get openNow;

  /// No description provided for @closedNow.
  ///
  /// In en, this message translates to:
  /// **'Closed'**
  String get closedNow;

  /// No description provided for @closesAt.
  ///
  /// In en, this message translates to:
  /// **'Closes {time}'**
  String closesAt(String time);

  /// No description provided for @opensAt.
  ///
  /// In en, this message translates to:
  /// **'Opens {time}'**
  String opensAt(String time);

  /// No description provided for @distanceKm.
  ///
  /// In en, this message translates to:
  /// **'{km} km'**
  String distanceKm(String km);

  /// No description provided for @selectThisBranch.
  ///
  /// In en, this message translates to:
  /// **'Shop at this branch'**
  String get selectThisBranch;

  /// No description provided for @selectedBranch.
  ///
  /// In en, this message translates to:
  /// **'Your branch'**
  String get selectedBranch;

  /// No description provided for @branchHours.
  ///
  /// In en, this message translates to:
  /// **'Opening hours'**
  String get branchHours;

  /// No description provided for @holidays.
  ///
  /// In en, this message translates to:
  /// **'Holidays & special hours'**
  String get holidays;

  /// No description provided for @closed.
  ///
  /// In en, this message translates to:
  /// **'Closed'**
  String get closed;

  /// No description provided for @breakTime.
  ///
  /// In en, this message translates to:
  /// **'Break {from}–{to}'**
  String breakTime(String from, String to);

  /// No description provided for @branchServices.
  ///
  /// In en, this message translates to:
  /// **'Services'**
  String get branchServices;

  /// No description provided for @serviceCod.
  ///
  /// In en, this message translates to:
  /// **'Cash on delivery'**
  String get serviceCod;

  /// No description provided for @servicePayAtPickup.
  ///
  /// In en, this message translates to:
  /// **'Pay at pickup'**
  String get servicePayAtPickup;

  /// No description provided for @branchSelectedToast.
  ///
  /// In en, this message translates to:
  /// **'Now shopping at {name}'**
  String branchSelectedToast(String name);

  /// No description provided for @branchSearchHint.
  ///
  /// In en, this message translates to:
  /// **'Search showrooms or areas'**
  String get branchSearchHint;

  /// No description provided for @noBranches.
  ///
  /// In en, this message translates to:
  /// **'No showrooms match your search.'**
  String get noBranches;

  /// No description provided for @branchChangeNote.
  ///
  /// In en, this message translates to:
  /// **'Prices and stock will update for this branch. Existing orders are not affected.'**
  String get branchChangeNote;

  /// No description provided for @mapAttribution.
  ///
  /// In en, this message translates to:
  /// **'© OpenStreetMap contributors'**
  String get mapAttribution;

  /// No description provided for @cartTitle.
  ///
  /// In en, this message translates to:
  /// **'Your cart'**
  String get cartTitle;

  /// No description provided for @cartEmpty.
  ///
  /// In en, this message translates to:
  /// **'Your cart is empty'**
  String get cartEmpty;

  /// No description provided for @cartEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Pieces you add will be kept here, ready when you are.'**
  String get cartEmptyMessage;

  /// No description provided for @startShopping.
  ///
  /// In en, this message translates to:
  /// **'Start shopping'**
  String get startShopping;

  /// No description provided for @subtotal.
  ///
  /// In en, this message translates to:
  /// **'Subtotal'**
  String get subtotal;

  /// No description provided for @itemsCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =0{No items} =1{1 item} other{{count} items}}'**
  String itemsCount(int count);

  /// No description provided for @couponCode.
  ///
  /// In en, this message translates to:
  /// **'Promo code'**
  String get couponCode;

  /// No description provided for @applyCoupon.
  ///
  /// In en, this message translates to:
  /// **'Apply'**
  String get applyCoupon;

  /// No description provided for @removeCoupon.
  ///
  /// In en, this message translates to:
  /// **'Remove code'**
  String get removeCoupon;

  /// No description provided for @couponApplied.
  ///
  /// In en, this message translates to:
  /// **'Code {code} applied'**
  String couponApplied(String code);

  /// No description provided for @proceedToCheckout.
  ///
  /// In en, this message translates to:
  /// **'Proceed to checkout'**
  String get proceedToCheckout;

  /// No description provided for @itemUnavailable.
  ///
  /// In en, this message translates to:
  /// **'Not available at this branch'**
  String get itemUnavailable;

  /// No description provided for @itemInsufficientStock.
  ///
  /// In en, this message translates to:
  /// **'Only {count} available — please reduce quantity'**
  String itemInsufficientStock(int count);

  /// No description provided for @itemInactive.
  ///
  /// In en, this message translates to:
  /// **'No longer available'**
  String get itemInactive;

  /// No description provided for @priceChangedFrom.
  ///
  /// In en, this message translates to:
  /// **'Price changed from {price}'**
  String priceChangedFrom(String price);

  /// No description provided for @removeItemConfirm.
  ///
  /// In en, this message translates to:
  /// **'Remove this item from your cart?'**
  String get removeItemConfirm;

  /// No description provided for @cartHasIssues.
  ///
  /// In en, this message translates to:
  /// **'Some items need your attention before checkout.'**
  String get cartHasIssues;

  /// No description provided for @totalsCalculatedAtCheckout.
  ///
  /// In en, this message translates to:
  /// **'Delivery and final totals are confirmed at checkout.'**
  String get totalsCalculatedAtCheckout;

  /// No description provided for @checkoutTitle.
  ///
  /// In en, this message translates to:
  /// **'Checkout'**
  String get checkoutTitle;

  /// No description provided for @fulfillmentMethod.
  ///
  /// In en, this message translates to:
  /// **'How would you like to receive it?'**
  String get fulfillmentMethod;

  /// No description provided for @delivery.
  ///
  /// In en, this message translates to:
  /// **'Delivery'**
  String get delivery;

  /// No description provided for @pickup.
  ///
  /// In en, this message translates to:
  /// **'Pickup'**
  String get pickup;

  /// No description provided for @deliveryAddress.
  ///
  /// In en, this message translates to:
  /// **'Delivery address'**
  String get deliveryAddress;

  /// No description provided for @addAddress.
  ///
  /// In en, this message translates to:
  /// **'Add address'**
  String get addAddress;

  /// No description provided for @pickupBranch.
  ///
  /// In en, this message translates to:
  /// **'Pickup showroom'**
  String get pickupBranch;

  /// No description provided for @pickupSlot.
  ///
  /// In en, this message translates to:
  /// **'Pickup time'**
  String get pickupSlot;

  /// No description provided for @noSlots.
  ///
  /// In en, this message translates to:
  /// **'No pickup times on this day.'**
  String get noSlots;

  /// No description provided for @slotFull.
  ///
  /// In en, this message translates to:
  /// **'Full'**
  String get slotFull;

  /// No description provided for @slotRemaining.
  ///
  /// In en, this message translates to:
  /// **'{count} left'**
  String slotRemaining(int count);

  /// No description provided for @paymentMethod.
  ///
  /// In en, this message translates to:
  /// **'Payment'**
  String get paymentMethod;

  /// No description provided for @paySadad.
  ///
  /// In en, this message translates to:
  /// **'SADAD online payment'**
  String get paySadad;

  /// No description provided for @payCod.
  ///
  /// In en, this message translates to:
  /// **'Cash on delivery'**
  String get payCod;

  /// No description provided for @payAtPickup.
  ///
  /// In en, this message translates to:
  /// **'Pay at pickup'**
  String get payAtPickup;

  /// No description provided for @paymentUnavailable.
  ///
  /// In en, this message translates to:
  /// **'Not available for this order'**
  String get paymentUnavailable;

  /// No description provided for @paymentReasonCodLimit.
  ///
  /// In en, this message translates to:
  /// **'Order exceeds the cash-on-delivery limit'**
  String get paymentReasonCodLimit;

  /// No description provided for @loyaltyPoints.
  ///
  /// In en, this message translates to:
  /// **'Loyalty points'**
  String get loyaltyPoints;

  /// No description provided for @usePoints.
  ///
  /// In en, this message translates to:
  /// **'Redeem points'**
  String get usePoints;

  /// No description provided for @pointsBalance.
  ///
  /// In en, this message translates to:
  /// **'Balance: {points} pts'**
  String pointsBalance(int points);

  /// No description provided for @maxRedeemable.
  ///
  /// In en, this message translates to:
  /// **'Up to {points} pts on this order'**
  String maxRedeemable(int points);

  /// No description provided for @pointsToEarn.
  ///
  /// In en, this message translates to:
  /// **'You’ll earn {points} pts'**
  String pointsToEarn(int points);

  /// No description provided for @orderSummary.
  ///
  /// In en, this message translates to:
  /// **'Order summary'**
  String get orderSummary;

  /// No description provided for @discount.
  ///
  /// In en, this message translates to:
  /// **'Discount'**
  String get discount;

  /// No description provided for @deliveryFee.
  ///
  /// In en, this message translates to:
  /// **'Delivery'**
  String get deliveryFee;

  /// No description provided for @serviceFee.
  ///
  /// In en, this message translates to:
  /// **'Service fee'**
  String get serviceFee;

  /// No description provided for @codFee.
  ///
  /// In en, this message translates to:
  /// **'Cash on delivery fee'**
  String get codFee;

  /// No description provided for @tax.
  ///
  /// In en, this message translates to:
  /// **'Tax'**
  String get tax;

  /// No description provided for @loyaltyDiscount.
  ///
  /// In en, this message translates to:
  /// **'Points redeemed'**
  String get loyaltyDiscount;

  /// No description provided for @grandTotal.
  ///
  /// In en, this message translates to:
  /// **'Total'**
  String get grandTotal;

  /// No description provided for @splitFulfillmentTitle.
  ///
  /// In en, this message translates to:
  /// **'Your order arrives in {count} parts'**
  String splitFulfillmentTitle(int count);

  /// No description provided for @splitFulfillmentMessage.
  ///
  /// In en, this message translates to:
  /// **'Items are prepared at different showrooms to get everything to you sooner.'**
  String get splitFulfillmentMessage;

  /// No description provided for @fulfilledFrom.
  ///
  /// In en, this message translates to:
  /// **'From {branch}'**
  String fulfilledFrom(String branch);

  /// No description provided for @etaMinutes.
  ///
  /// In en, this message translates to:
  /// **'Estimated {time}'**
  String etaMinutes(String time);

  /// No description provided for @hoursShort.
  ///
  /// In en, this message translates to:
  /// **'{hours} h'**
  String hoursShort(int hours);

  /// No description provided for @minutesShort.
  ///
  /// In en, this message translates to:
  /// **'{minutes} min'**
  String minutesShort(int minutes);

  /// No description provided for @allowSplit.
  ///
  /// In en, this message translates to:
  /// **'Allow split shipments'**
  String get allowSplit;

  /// No description provided for @allowSplitHint.
  ///
  /// In en, this message translates to:
  /// **'Receive items from multiple showrooms'**
  String get allowSplitHint;

  /// No description provided for @customerNotes.
  ///
  /// In en, this message translates to:
  /// **'Notes for our team'**
  String get customerNotes;

  /// No description provided for @placeOrder.
  ///
  /// In en, this message translates to:
  /// **'Place order'**
  String get placeOrder;

  /// No description provided for @quoteChangedTitle.
  ///
  /// In en, this message translates to:
  /// **'Your totals were updated'**
  String get quoteChangedTitle;

  /// No description provided for @quoteChangedMessage.
  ///
  /// In en, this message translates to:
  /// **'Prices, stock or fees changed since you started checkout. Please review the new totals.'**
  String get quoteChangedMessage;

  /// No description provided for @reviewAndConfirm.
  ///
  /// In en, this message translates to:
  /// **'Review & confirm'**
  String get reviewAndConfirm;

  /// No description provided for @previousTotal.
  ///
  /// In en, this message translates to:
  /// **'Previously {amount}'**
  String previousTotal(String amount);

  /// No description provided for @cannotPlaceOrder.
  ///
  /// In en, this message translates to:
  /// **'This order can’t be placed yet'**
  String get cannotPlaceOrder;

  /// No description provided for @orderPlaced.
  ///
  /// In en, this message translates to:
  /// **'Thank you!'**
  String get orderPlaced;

  /// No description provided for @orderPlacedMessage.
  ///
  /// In en, this message translates to:
  /// **'Order {number} is confirmed.'**
  String orderPlacedMessage(String number);

  /// No description provided for @viewOrder.
  ///
  /// In en, this message translates to:
  /// **'View order'**
  String get viewOrder;

  /// No description provided for @continueShopping.
  ///
  /// In en, this message translates to:
  /// **'Continue shopping'**
  String get continueShopping;

  /// No description provided for @selectAddressFirst.
  ///
  /// In en, this message translates to:
  /// **'Choose a delivery address'**
  String get selectAddressFirst;

  /// No description provided for @selectBranchFirst.
  ///
  /// In en, this message translates to:
  /// **'Choose a pickup showroom'**
  String get selectBranchFirst;

  /// No description provided for @selectPaymentFirst.
  ///
  /// In en, this message translates to:
  /// **'Choose a payment method'**
  String get selectPaymentFirst;

  /// No description provided for @calculating.
  ///
  /// In en, this message translates to:
  /// **'Calculating…'**
  String get calculating;

  /// No description provided for @free.
  ///
  /// In en, this message translates to:
  /// **'Free'**
  String get free;

  /// No description provided for @deliveryDate.
  ///
  /// In en, this message translates to:
  /// **'Delivery date'**
  String get deliveryDate;

  /// No description provided for @paymentTitle.
  ///
  /// In en, this message translates to:
  /// **'Payment'**
  String get paymentTitle;

  /// No description provided for @paymentPending.
  ///
  /// In en, this message translates to:
  /// **'Waiting for payment confirmation…'**
  String get paymentPending;

  /// No description provided for @paymentPendingHint.
  ///
  /// In en, this message translates to:
  /// **'This can take a moment. Please don’t close the app.'**
  String get paymentPendingHint;

  /// No description provided for @paymentPaid.
  ///
  /// In en, this message translates to:
  /// **'Payment received'**
  String get paymentPaid;

  /// No description provided for @paymentFailed.
  ///
  /// In en, this message translates to:
  /// **'Payment was not completed'**
  String get paymentFailed;

  /// No description provided for @paymentExpired.
  ///
  /// In en, this message translates to:
  /// **'Payment session expired'**
  String get paymentExpired;

  /// No description provided for @paymentCancelled.
  ///
  /// In en, this message translates to:
  /// **'Payment cancelled'**
  String get paymentCancelled;

  /// No description provided for @paymentStillPending.
  ///
  /// In en, this message translates to:
  /// **'We haven’t received confirmation yet. You can check again or view your order.'**
  String get paymentStillPending;

  /// No description provided for @checkAgain.
  ///
  /// In en, this message translates to:
  /// **'Check again'**
  String get checkAgain;

  /// No description provided for @retryPayment.
  ///
  /// In en, this message translates to:
  /// **'Try payment again'**
  String get retryPayment;

  /// No description provided for @payNow.
  ///
  /// In en, this message translates to:
  /// **'Pay now'**
  String get payNow;

  /// No description provided for @openingPayment.
  ///
  /// In en, this message translates to:
  /// **'Opening secure SADAD payment…'**
  String get openingPayment;

  /// No description provided for @ordersTitle.
  ///
  /// In en, this message translates to:
  /// **'My orders'**
  String get ordersTitle;

  /// No description provided for @noOrders.
  ///
  /// In en, this message translates to:
  /// **'No orders yet'**
  String get noOrders;

  /// No description provided for @noOrdersMessage.
  ///
  /// In en, this message translates to:
  /// **'When you place an order, you can follow it here.'**
  String get noOrdersMessage;

  /// No description provided for @orderNumber.
  ///
  /// In en, this message translates to:
  /// **'Order {number}'**
  String orderNumber(String number);

  /// No description provided for @placedOn.
  ///
  /// In en, this message translates to:
  /// **'Placed {date}'**
  String placedOn(String date);

  /// No description provided for @orderItems.
  ///
  /// In en, this message translates to:
  /// **'Items'**
  String get orderItems;

  /// No description provided for @orderTimeline.
  ///
  /// In en, this message translates to:
  /// **'Progress'**
  String get orderTimeline;

  /// No description provided for @cancelOrder.
  ///
  /// In en, this message translates to:
  /// **'Cancel order'**
  String get cancelOrder;

  /// No description provided for @cancelOrderTitle.
  ///
  /// In en, this message translates to:
  /// **'Cancel this order?'**
  String get cancelOrderTitle;

  /// No description provided for @cancelReason.
  ///
  /// In en, this message translates to:
  /// **'Reason'**
  String get cancelReason;

  /// No description provided for @cancelReasonChangedMind.
  ///
  /// In en, this message translates to:
  /// **'I changed my mind'**
  String get cancelReasonChangedMind;

  /// No description provided for @cancelReasonMistake.
  ///
  /// In en, this message translates to:
  /// **'Ordered by mistake'**
  String get cancelReasonMistake;

  /// No description provided for @cancelReasonDelay.
  ///
  /// In en, this message translates to:
  /// **'Delivery takes too long'**
  String get cancelReasonDelay;

  /// No description provided for @cancelReasonPrice.
  ///
  /// In en, this message translates to:
  /// **'Found a better price'**
  String get cancelReasonPrice;

  /// No description provided for @cancelReasonOther.
  ///
  /// In en, this message translates to:
  /// **'Other'**
  String get cancelReasonOther;

  /// No description provided for @cancelDetails.
  ///
  /// In en, this message translates to:
  /// **'Tell us more (optional)'**
  String get cancelDetails;

  /// No description provided for @cancelRequested.
  ///
  /// In en, this message translates to:
  /// **'Cancellation requested — we’ll confirm shortly.'**
  String get cancelRequested;

  /// No description provided for @cancelApproved.
  ///
  /// In en, this message translates to:
  /// **'Your order has been cancelled.'**
  String get cancelApproved;

  /// No description provided for @keepOrder.
  ///
  /// In en, this message translates to:
  /// **'Keep order'**
  String get keepOrder;

  /// No description provided for @reorder.
  ///
  /// In en, this message translates to:
  /// **'Order again'**
  String get reorder;

  /// No description provided for @reorderDone.
  ///
  /// In en, this message translates to:
  /// **'Available items were added to your cart.'**
  String get reorderDone;

  /// No description provided for @reorderSkipped.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 item is no longer available.} other{{count} items are no longer available.}}'**
  String reorderSkipped(int count);

  /// No description provided for @downloadInvoice.
  ///
  /// In en, this message translates to:
  /// **'Invoice (PDF)'**
  String get downloadInvoice;

  /// No description provided for @pickupPass.
  ///
  /// In en, this message translates to:
  /// **'Pickup pass'**
  String get pickupPass;

  /// No description provided for @pickupPassTitle.
  ///
  /// In en, this message translates to:
  /// **'Your pickup pass'**
  String get pickupPassTitle;

  /// No description provided for @pickupPassInstructions.
  ///
  /// In en, this message translates to:
  /// **'Show this code at the showroom counter to collect your order.'**
  String get pickupPassInstructions;

  /// No description provided for @passExpires.
  ///
  /// In en, this message translates to:
  /// **'Valid until {time}'**
  String passExpires(String time);

  /// No description provided for @trackDelivery.
  ///
  /// In en, this message translates to:
  /// **'Delivery tracking'**
  String get trackDelivery;

  /// No description provided for @driver.
  ///
  /// In en, this message translates to:
  /// **'Driver'**
  String get driver;

  /// No description provided for @trackingNumber.
  ///
  /// In en, this message translates to:
  /// **'Tracking number'**
  String get trackingNumber;

  /// No description provided for @deliveryProvider.
  ///
  /// In en, this message translates to:
  /// **'Carrier'**
  String get deliveryProvider;

  /// No description provided for @eta.
  ///
  /// In en, this message translates to:
  /// **'Estimated arrival'**
  String get eta;

  /// No description provided for @shipment.
  ///
  /// In en, this message translates to:
  /// **'Shipment {number}'**
  String shipment(String number);

  /// No description provided for @shippingTo.
  ///
  /// In en, this message translates to:
  /// **'Delivering to'**
  String get shippingTo;

  /// No description provided for @paymentLabel.
  ///
  /// In en, this message translates to:
  /// **'Payment'**
  String get paymentLabel;

  /// No description provided for @filterAll.
  ///
  /// In en, this message translates to:
  /// **'All'**
  String get filterAll;

  /// No description provided for @filterActive.
  ///
  /// In en, this message translates to:
  /// **'Active'**
  String get filterActive;

  /// No description provided for @filterCompleted.
  ///
  /// In en, this message translates to:
  /// **'Completed'**
  String get filterCompleted;

  /// No description provided for @filterCancelled.
  ///
  /// In en, this message translates to:
  /// **'Cancelled'**
  String get filterCancelled;

  /// No description provided for @contactSupport.
  ///
  /// In en, this message translates to:
  /// **'Get help with this order'**
  String get contactSupport;

  /// No description provided for @requestReturn.
  ///
  /// In en, this message translates to:
  /// **'Request a return'**
  String get requestReturn;

  /// No description provided for @returnReasonDamaged.
  ///
  /// In en, this message translates to:
  /// **'Arrived damaged'**
  String get returnReasonDamaged;

  /// No description provided for @returnReasonWrong.
  ///
  /// In en, this message translates to:
  /// **'Wrong item'**
  String get returnReasonWrong;

  /// No description provided for @returnReasonNotAsDescribed.
  ///
  /// In en, this message translates to:
  /// **'Not as described'**
  String get returnReasonNotAsDescribed;

  /// No description provided for @returnRequested.
  ///
  /// In en, this message translates to:
  /// **'Return requested. Our team will contact you.'**
  String get returnRequested;

  /// No description provided for @selectItemsToReturn.
  ///
  /// In en, this message translates to:
  /// **'Select items to return'**
  String get selectItemsToReturn;

  /// No description provided for @slotLabel.
  ///
  /// In en, this message translates to:
  /// **'{date}, {from}–{to}'**
  String slotLabel(String date, String from, String to);

  /// No description provided for @wishlistTitle.
  ///
  /// In en, this message translates to:
  /// **'Wishlist'**
  String get wishlistTitle;

  /// No description provided for @wishlistEmpty.
  ///
  /// In en, this message translates to:
  /// **'Nothing saved yet'**
  String get wishlistEmpty;

  /// No description provided for @wishlistEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Tap the heart on any piece to keep it here.'**
  String get wishlistEmptyMessage;

  /// No description provided for @addedToWishlist.
  ///
  /// In en, this message translates to:
  /// **'Saved to your wishlist'**
  String get addedToWishlist;

  /// No description provided for @removedFromWishlist.
  ///
  /// In en, this message translates to:
  /// **'Removed from your wishlist'**
  String get removedFromWishlist;

  /// No description provided for @auctionsTitle.
  ///
  /// In en, this message translates to:
  /// **'Auctions'**
  String get auctionsTitle;

  /// No description provided for @auctionUpcoming.
  ///
  /// In en, this message translates to:
  /// **'Upcoming'**
  String get auctionUpcoming;

  /// No description provided for @auctionLive.
  ///
  /// In en, this message translates to:
  /// **'Live'**
  String get auctionLive;

  /// No description provided for @auctionCompleted.
  ///
  /// In en, this message translates to:
  /// **'Ended'**
  String get auctionCompleted;

  /// No description provided for @noAuctions.
  ///
  /// In en, this message translates to:
  /// **'No auctions here right now'**
  String get noAuctions;

  /// No description provided for @noAuctionsMessage.
  ///
  /// In en, this message translates to:
  /// **'Turn on auction notifications to hear about the next one.'**
  String get noAuctionsMessage;

  /// No description provided for @lotsCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 lot} other{{count} lots}}'**
  String lotsCount(int count);

  /// No description provided for @lotNumber.
  ///
  /// In en, this message translates to:
  /// **'Lot {number}'**
  String lotNumber(int number);

  /// No description provided for @currentBid.
  ///
  /// In en, this message translates to:
  /// **'Current bid'**
  String get currentBid;

  /// No description provided for @startingPrice.
  ///
  /// In en, this message translates to:
  /// **'Starting price'**
  String get startingPrice;

  /// No description provided for @minNextBid.
  ///
  /// In en, this message translates to:
  /// **'Minimum next bid'**
  String get minNextBid;

  /// No description provided for @minIncrement.
  ///
  /// In en, this message translates to:
  /// **'Increment'**
  String get minIncrement;

  /// No description provided for @buyNowPrice.
  ///
  /// In en, this message translates to:
  /// **'Buy now'**
  String get buyNowPrice;

  /// No description provided for @reserveMet.
  ///
  /// In en, this message translates to:
  /// **'Reserve met'**
  String get reserveMet;

  /// No description provided for @reserveNotMet.
  ///
  /// In en, this message translates to:
  /// **'Reserve not met'**
  String get reserveNotMet;

  /// No description provided for @bidCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =0{No bids} =1{1 bid} other{{count} bids}}'**
  String bidCount(int count);

  /// No description provided for @placeBid.
  ///
  /// In en, this message translates to:
  /// **'Place bid'**
  String get placeBid;

  /// No description provided for @bidAmount.
  ///
  /// In en, this message translates to:
  /// **'Your bid (QAR)'**
  String get bidAmount;

  /// No description provided for @bidPlaced.
  ///
  /// In en, this message translates to:
  /// **'Bid placed'**
  String get bidPlaced;

  /// No description provided for @bidTooLow.
  ///
  /// In en, this message translates to:
  /// **'Bid at least {amount}'**
  String bidTooLow(String amount);

  /// No description provided for @youAreLeading.
  ///
  /// In en, this message translates to:
  /// **'You’re the highest bidder'**
  String get youAreLeading;

  /// No description provided for @youWereOutbid.
  ///
  /// In en, this message translates to:
  /// **'You’ve been outbid'**
  String get youWereOutbid;

  /// No description provided for @youWon.
  ///
  /// In en, this message translates to:
  /// **'You won this lot'**
  String get youWon;

  /// No description provided for @youLost.
  ///
  /// In en, this message translates to:
  /// **'This lot went to another bidder'**
  String get youLost;

  /// No description provided for @autoBid.
  ///
  /// In en, this message translates to:
  /// **'Auto-bid'**
  String get autoBid;

  /// No description provided for @autoBidHint.
  ///
  /// In en, this message translates to:
  /// **'We’ll bid for you, one increment at a time, up to your maximum.'**
  String get autoBidHint;

  /// No description provided for @autoBidMax.
  ///
  /// In en, this message translates to:
  /// **'Maximum amount (QAR)'**
  String get autoBidMax;

  /// No description provided for @autoBidActive.
  ///
  /// In en, this message translates to:
  /// **'Auto-bid up to {amount}'**
  String autoBidActive(String amount);

  /// No description provided for @setAutoBid.
  ///
  /// In en, this message translates to:
  /// **'Set auto-bid'**
  String get setAutoBid;

  /// No description provided for @cancelAutoBid.
  ///
  /// In en, this message translates to:
  /// **'Stop auto-bid'**
  String get cancelAutoBid;

  /// No description provided for @watch.
  ///
  /// In en, this message translates to:
  /// **'Watch'**
  String get watch;

  /// No description provided for @watching.
  ///
  /// In en, this message translates to:
  /// **'Watching'**
  String get watching;

  /// No description provided for @registerToBid.
  ///
  /// In en, this message translates to:
  /// **'Register to bid'**
  String get registerToBid;

  /// No description provided for @acceptTerms.
  ///
  /// In en, this message translates to:
  /// **'I have read and accept the auction terms'**
  String get acceptTerms;

  /// No description provided for @auctionTerms.
  ///
  /// In en, this message translates to:
  /// **'Auction terms'**
  String get auctionTerms;

  /// No description provided for @registeredPaddle.
  ///
  /// In en, this message translates to:
  /// **'Registered · Paddle {paddle}'**
  String registeredPaddle(String paddle);

  /// No description provided for @registrationPending.
  ///
  /// In en, this message translates to:
  /// **'Registration pending approval'**
  String get registrationPending;

  /// No description provided for @depositRequired.
  ///
  /// In en, this message translates to:
  /// **'A refundable deposit of {amount} is required'**
  String depositRequired(String amount);

  /// No description provided for @payDeposit.
  ///
  /// In en, this message translates to:
  /// **'Pay deposit'**
  String get payDeposit;

  /// No description provided for @bidHistory.
  ///
  /// In en, this message translates to:
  /// **'Bid history'**
  String get bidHistory;

  /// No description provided for @noBids.
  ///
  /// In en, this message translates to:
  /// **'No bids yet — be the first.'**
  String get noBids;

  /// No description provided for @lotExtended.
  ///
  /// In en, this message translates to:
  /// **'Time extended'**
  String get lotExtended;

  /// No description provided for @lotEnded.
  ///
  /// In en, this message translates to:
  /// **'Bidding has ended'**
  String get lotEnded;

  /// No description provided for @lotNotStarted.
  ///
  /// In en, this message translates to:
  /// **'Bidding hasn’t started'**
  String get lotNotStarted;

  /// No description provided for @lotStatusScheduled.
  ///
  /// In en, this message translates to:
  /// **'Scheduled'**
  String get lotStatusScheduled;

  /// No description provided for @lotStatusOpen.
  ///
  /// In en, this message translates to:
  /// **'Open'**
  String get lotStatusOpen;

  /// No description provided for @lotStatusClosing.
  ///
  /// In en, this message translates to:
  /// **'Closing'**
  String get lotStatusClosing;

  /// No description provided for @lotStatusClosed.
  ///
  /// In en, this message translates to:
  /// **'Closed'**
  String get lotStatusClosed;

  /// No description provided for @lotStatusSold.
  ///
  /// In en, this message translates to:
  /// **'Sold'**
  String get lotStatusSold;

  /// No description provided for @lotStatusUnsold.
  ///
  /// In en, this message translates to:
  /// **'Unsold'**
  String get lotStatusUnsold;

  /// No description provided for @lotStatusCancelled.
  ///
  /// In en, this message translates to:
  /// **'Cancelled'**
  String get lotStatusCancelled;

  /// No description provided for @myAuctions.
  ///
  /// In en, this message translates to:
  /// **'My auctions'**
  String get myAuctions;

  /// No description provided for @myBids.
  ///
  /// In en, this message translates to:
  /// **'Bids'**
  String get myBids;

  /// No description provided for @myWatchlist.
  ///
  /// In en, this message translates to:
  /// **'Watchlist'**
  String get myWatchlist;

  /// No description provided for @myWins.
  ///
  /// In en, this message translates to:
  /// **'Wins'**
  String get myWins;

  /// No description provided for @checkoutWin.
  ///
  /// In en, this message translates to:
  /// **'Complete purchase'**
  String get checkoutWin;

  /// No description provided for @winCheckoutTitle.
  ///
  /// In en, this message translates to:
  /// **'Complete your win'**
  String get winCheckoutTitle;

  /// No description provided for @realtimeLive.
  ///
  /// In en, this message translates to:
  /// **'Live'**
  String get realtimeLive;

  /// No description provided for @realtimeReconnecting.
  ///
  /// In en, this message translates to:
  /// **'Reconnecting…'**
  String get realtimeReconnecting;

  /// No description provided for @confirmBidTitle.
  ///
  /// In en, this message translates to:
  /// **'Confirm your bid'**
  String get confirmBidTitle;

  /// No description provided for @confirmBidMessage.
  ///
  /// In en, this message translates to:
  /// **'Place a bid of {amount}? Bids are binding.'**
  String confirmBidMessage(String amount);

  /// No description provided for @lots.
  ///
  /// In en, this message translates to:
  /// **'Lots'**
  String get lots;

  /// No description provided for @viewLot.
  ///
  /// In en, this message translates to:
  /// **'View lot'**
  String get viewLot;

  /// No description provided for @auctionStartsAt.
  ///
  /// In en, this message translates to:
  /// **'Starts {date}'**
  String auctionStartsAt(String date);

  /// No description provided for @auctionEndedAt.
  ///
  /// In en, this message translates to:
  /// **'Ended {date}'**
  String auctionEndedAt(String date);

  /// No description provided for @winningBid.
  ///
  /// In en, this message translates to:
  /// **'Winning bid'**
  String get winningBid;

  /// No description provided for @payBy.
  ///
  /// In en, this message translates to:
  /// **'Pay by {date}'**
  String payBy(String date);

  /// No description provided for @watchAdded.
  ///
  /// In en, this message translates to:
  /// **'Added to your watchlist'**
  String get watchAdded;

  /// No description provided for @watchRemoved.
  ///
  /// In en, this message translates to:
  /// **'Removed from your watchlist'**
  String get watchRemoved;

  /// No description provided for @notificationsTitle.
  ///
  /// In en, this message translates to:
  /// **'Notifications'**
  String get notificationsTitle;

  /// No description provided for @noNotifications.
  ///
  /// In en, this message translates to:
  /// **'You’re all caught up'**
  String get noNotifications;

  /// No description provided for @noNotificationsMessage.
  ///
  /// In en, this message translates to:
  /// **'Order updates and auction alerts will appear here.'**
  String get noNotificationsMessage;

  /// No description provided for @markAllRead.
  ///
  /// In en, this message translates to:
  /// **'Mark all read'**
  String get markAllRead;

  /// No description provided for @accountTitle.
  ///
  /// In en, this message translates to:
  /// **'Account'**
  String get accountTitle;

  /// No description provided for @guestAccountTitle.
  ///
  /// In en, this message translates to:
  /// **'Make it yours'**
  String get guestAccountTitle;

  /// No description provided for @guestAccountMessage.
  ///
  /// In en, this message translates to:
  /// **'Sign in to track orders, save favourites and join auctions.'**
  String get guestAccountMessage;

  /// No description provided for @myOrders.
  ///
  /// In en, this message translates to:
  /// **'Orders'**
  String get myOrders;

  /// No description provided for @myAddresses.
  ///
  /// In en, this message translates to:
  /// **'Addresses'**
  String get myAddresses;

  /// No description provided for @myWishlist.
  ///
  /// In en, this message translates to:
  /// **'Wishlist'**
  String get myWishlist;

  /// No description provided for @loyaltyAndReferral.
  ///
  /// In en, this message translates to:
  /// **'Rewards & referrals'**
  String get loyaltyAndReferral;

  /// No description provided for @notificationPreferences.
  ///
  /// In en, this message translates to:
  /// **'Notification preferences'**
  String get notificationPreferences;

  /// No description provided for @support.
  ///
  /// In en, this message translates to:
  /// **'Help & support'**
  String get support;

  /// No description provided for @settings.
  ///
  /// In en, this message translates to:
  /// **'Settings'**
  String get settings;

  /// No description provided for @editProfile.
  ///
  /// In en, this message translates to:
  /// **'Edit profile'**
  String get editProfile;

  /// No description provided for @profileSaved.
  ///
  /// In en, this message translates to:
  /// **'Profile updated'**
  String get profileSaved;

  /// No description provided for @dateOfBirth.
  ///
  /// In en, this message translates to:
  /// **'Date of birth'**
  String get dateOfBirth;

  /// No description provided for @gender.
  ///
  /// In en, this message translates to:
  /// **'Gender'**
  String get gender;

  /// No description provided for @male.
  ///
  /// In en, this message translates to:
  /// **'Male'**
  String get male;

  /// No description provided for @female.
  ///
  /// In en, this message translates to:
  /// **'Female'**
  String get female;

  /// No description provided for @notSpecified.
  ///
  /// In en, this message translates to:
  /// **'Not specified'**
  String get notSpecified;

  /// No description provided for @changePassword.
  ///
  /// In en, this message translates to:
  /// **'Change password'**
  String get changePassword;

  /// No description provided for @setPassword.
  ///
  /// In en, this message translates to:
  /// **'Set a password'**
  String get setPassword;

  /// No description provided for @currentPassword.
  ///
  /// In en, this message translates to:
  /// **'Current password'**
  String get currentPassword;

  /// No description provided for @passwordChanged.
  ///
  /// In en, this message translates to:
  /// **'Password updated'**
  String get passwordChanged;

  /// No description provided for @memberSince.
  ///
  /// In en, this message translates to:
  /// **'Member since {date}'**
  String memberSince(String date);

  /// No description provided for @addressesTitle.
  ///
  /// In en, this message translates to:
  /// **'Saved addresses'**
  String get addressesTitle;

  /// No description provided for @noAddresses.
  ///
  /// In en, this message translates to:
  /// **'No saved addresses'**
  String get noAddresses;

  /// No description provided for @noAddressesMessage.
  ///
  /// In en, this message translates to:
  /// **'Add an address for faster checkout.'**
  String get noAddressesMessage;

  /// No description provided for @newAddress.
  ///
  /// In en, this message translates to:
  /// **'New address'**
  String get newAddress;

  /// No description provided for @editAddress.
  ///
  /// In en, this message translates to:
  /// **'Edit address'**
  String get editAddress;

  /// No description provided for @addressLabel.
  ///
  /// In en, this message translates to:
  /// **'Label'**
  String get addressLabel;

  /// No description provided for @addressLabelHint.
  ///
  /// In en, this message translates to:
  /// **'Home, Office…'**
  String get addressLabelHint;

  /// No description provided for @recipientName.
  ///
  /// In en, this message translates to:
  /// **'Recipient name'**
  String get recipientName;

  /// No description provided for @recipientPhone.
  ///
  /// In en, this message translates to:
  /// **'Recipient mobile'**
  String get recipientPhone;

  /// No description provided for @deliveryZone.
  ///
  /// In en, this message translates to:
  /// **'Delivery zone'**
  String get deliveryZone;

  /// No description provided for @zoneNumber.
  ///
  /// In en, this message translates to:
  /// **'Zone'**
  String get zoneNumber;

  /// No description provided for @streetNumber.
  ///
  /// In en, this message translates to:
  /// **'Street'**
  String get streetNumber;

  /// No description provided for @buildingNumber.
  ///
  /// In en, this message translates to:
  /// **'Building'**
  String get buildingNumber;

  /// No description provided for @unitNumber.
  ///
  /// In en, this message translates to:
  /// **'Unit / flat'**
  String get unitNumber;

  /// No description provided for @area.
  ///
  /// In en, this message translates to:
  /// **'Area'**
  String get area;

  /// No description provided for @city.
  ///
  /// In en, this message translates to:
  /// **'City'**
  String get city;

  /// No description provided for @landmark.
  ///
  /// In en, this message translates to:
  /// **'Landmark'**
  String get landmark;

  /// No description provided for @instructions.
  ///
  /// In en, this message translates to:
  /// **'Delivery instructions'**
  String get instructions;

  /// No description provided for @pinOnMap.
  ///
  /// In en, this message translates to:
  /// **'Pin location on map'**
  String get pinOnMap;

  /// No description provided for @pinSet.
  ///
  /// In en, this message translates to:
  /// **'Location pinned'**
  String get pinSet;

  /// No description provided for @moveMapToPin.
  ///
  /// In en, this message translates to:
  /// **'Move the map to place the pin on your door'**
  String get moveMapToPin;

  /// No description provided for @confirmLocation.
  ///
  /// In en, this message translates to:
  /// **'Confirm location'**
  String get confirmLocation;

  /// No description provided for @setAsDefault.
  ///
  /// In en, this message translates to:
  /// **'Set as default'**
  String get setAsDefault;

  /// No description provided for @defaultAddress.
  ///
  /// In en, this message translates to:
  /// **'Default'**
  String get defaultAddress;

  /// No description provided for @deleteAddressConfirm.
  ///
  /// In en, this message translates to:
  /// **'Delete this address?'**
  String get deleteAddressConfirm;

  /// No description provided for @addressSaved.
  ///
  /// In en, this message translates to:
  /// **'Address saved'**
  String get addressSaved;

  /// No description provided for @addressRequirement.
  ///
  /// In en, this message translates to:
  /// **'Enter zone, street and building numbers — or pin your location on the map.'**
  String get addressRequirement;

  /// No description provided for @useCurrentLocation.
  ///
  /// In en, this message translates to:
  /// **'Use current location'**
  String get useCurrentLocation;

  /// No description provided for @saveAddress.
  ///
  /// In en, this message translates to:
  /// **'Save address'**
  String get saveAddress;

  /// No description provided for @qatarAddressHint.
  ///
  /// In en, this message translates to:
  /// **'Find these numbers on the blue plate by your door.'**
  String get qatarAddressHint;

  /// No description provided for @channelPush.
  ///
  /// In en, this message translates to:
  /// **'Push'**
  String get channelPush;

  /// No description provided for @channelSms.
  ///
  /// In en, this message translates to:
  /// **'SMS'**
  String get channelSms;

  /// No description provided for @channelWhatsapp.
  ///
  /// In en, this message translates to:
  /// **'WhatsApp'**
  String get channelWhatsapp;

  /// No description provided for @channelEmail.
  ///
  /// In en, this message translates to:
  /// **'Email'**
  String get channelEmail;

  /// No description provided for @categoryTransactional.
  ///
  /// In en, this message translates to:
  /// **'Orders & account'**
  String get categoryTransactional;

  /// No description provided for @categoryTransactionalDesc.
  ///
  /// In en, this message translates to:
  /// **'Order, payment, delivery and security updates'**
  String get categoryTransactionalDesc;

  /// No description provided for @categoryMarketing.
  ///
  /// In en, this message translates to:
  /// **'Offers & inspiration'**
  String get categoryMarketing;

  /// No description provided for @categoryMarketingDesc.
  ///
  /// In en, this message translates to:
  /// **'New collections, promotions and events'**
  String get categoryMarketingDesc;

  /// No description provided for @categoryAuction.
  ///
  /// In en, this message translates to:
  /// **'Auctions'**
  String get categoryAuction;

  /// No description provided for @categoryAuctionDesc.
  ///
  /// In en, this message translates to:
  /// **'Outbid alerts, wins and upcoming auctions'**
  String get categoryAuctionDesc;

  /// No description provided for @prefsSaved.
  ///
  /// In en, this message translates to:
  /// **'Preferences saved'**
  String get prefsSaved;

  /// No description provided for @transactionalNote.
  ///
  /// In en, this message translates to:
  /// **'Some essential order messages are always sent.'**
  String get transactionalNote;

  /// No description provided for @loyaltyTitle.
  ///
  /// In en, this message translates to:
  /// **'Rewards'**
  String get loyaltyTitle;

  /// No description provided for @pointsUnit.
  ///
  /// In en, this message translates to:
  /// **'{points} pts'**
  String pointsUnit(int points);

  /// No description provided for @tierLabel.
  ///
  /// In en, this message translates to:
  /// **'{tier} member'**
  String tierLabel(String tier);

  /// No description provided for @pointsWorth.
  ///
  /// In en, this message translates to:
  /// **'Worth {value}'**
  String pointsWorth(String value);

  /// No description provided for @lifetimeEarned.
  ///
  /// In en, this message translates to:
  /// **'Lifetime earned: {points} pts'**
  String lifetimeEarned(int points);

  /// No description provided for @loyaltyHistory.
  ///
  /// In en, this message translates to:
  /// **'Points activity'**
  String get loyaltyHistory;

  /// No description provided for @noLoyaltyHistory.
  ///
  /// In en, this message translates to:
  /// **'Earn points with every purchase.'**
  String get noLoyaltyHistory;

  /// No description provided for @pointsExpire.
  ///
  /// In en, this message translates to:
  /// **'Expires {date}'**
  String pointsExpire(String date);

  /// No description provided for @referralTitle.
  ///
  /// In en, this message translates to:
  /// **'Invite friends'**
  String get referralTitle;

  /// No description provided for @referralBody.
  ///
  /// In en, this message translates to:
  /// **'Share your code. When friends shop, you both earn rewards.'**
  String get referralBody;

  /// No description provided for @yourCode.
  ///
  /// In en, this message translates to:
  /// **'Your code'**
  String get yourCode;

  /// No description provided for @shareReferral.
  ///
  /// In en, this message translates to:
  /// **'Share invite'**
  String get shareReferral;

  /// No description provided for @referralShareText.
  ///
  /// In en, this message translates to:
  /// **'Discover Al Bahar Home & Living. Use my code {code}: {url}'**
  String referralShareText(String code, String url);

  /// No description provided for @referredCount.
  ///
  /// In en, this message translates to:
  /// **'Friends joined'**
  String get referredCount;

  /// No description provided for @rewardedCount.
  ///
  /// In en, this message translates to:
  /// **'Rewards earned'**
  String get rewardedCount;

  /// No description provided for @writeReviewTitle.
  ///
  /// In en, this message translates to:
  /// **'Your review'**
  String get writeReviewTitle;

  /// No description provided for @yourRating.
  ///
  /// In en, this message translates to:
  /// **'How would you rate it?'**
  String get yourRating;

  /// No description provided for @reviewTitleLabel.
  ///
  /// In en, this message translates to:
  /// **'Headline'**
  String get reviewTitleLabel;

  /// No description provided for @reviewBodyLabel.
  ///
  /// In en, this message translates to:
  /// **'Tell others about it'**
  String get reviewBodyLabel;

  /// No description provided for @addPhotos.
  ///
  /// In en, this message translates to:
  /// **'Add photos'**
  String get addPhotos;

  /// No description provided for @maxPhotos.
  ///
  /// In en, this message translates to:
  /// **'Up to {count} photos'**
  String maxPhotos(int count);

  /// No description provided for @submitReview.
  ///
  /// In en, this message translates to:
  /// **'Submit review'**
  String get submitReview;

  /// No description provided for @reviewSubmitted.
  ///
  /// In en, this message translates to:
  /// **'Thank you! Your review will appear once approved.'**
  String get reviewSubmitted;

  /// No description provided for @ratingRequired.
  ///
  /// In en, this message translates to:
  /// **'Please choose a rating.'**
  String get ratingRequired;

  /// No description provided for @removePhoto.
  ///
  /// In en, this message translates to:
  /// **'Remove photo'**
  String get removePhoto;

  /// No description provided for @fromCamera.
  ///
  /// In en, this message translates to:
  /// **'Camera'**
  String get fromCamera;

  /// No description provided for @fromGallery.
  ///
  /// In en, this message translates to:
  /// **'Photo library'**
  String get fromGallery;

  /// No description provided for @starLabel.
  ///
  /// In en, this message translates to:
  /// **'{count} stars'**
  String starLabel(int count);

  /// No description provided for @supportTitle.
  ///
  /// In en, this message translates to:
  /// **'Help & support'**
  String get supportTitle;

  /// No description provided for @newTicket.
  ///
  /// In en, this message translates to:
  /// **'New request'**
  String get newTicket;

  /// No description provided for @noTickets.
  ///
  /// In en, this message translates to:
  /// **'No support requests'**
  String get noTickets;

  /// No description provided for @noTicketsMessage.
  ///
  /// In en, this message translates to:
  /// **'Need a hand? Our team replies in Arabic and English.'**
  String get noTicketsMessage;

  /// No description provided for @ticketCategory.
  ///
  /// In en, this message translates to:
  /// **'Topic'**
  String get ticketCategory;

  /// No description provided for @ticketCategoryOrder.
  ///
  /// In en, this message translates to:
  /// **'An order'**
  String get ticketCategoryOrder;

  /// No description provided for @ticketCategoryPayment.
  ///
  /// In en, this message translates to:
  /// **'Payment or refund'**
  String get ticketCategoryPayment;

  /// No description provided for @ticketCategoryDelivery.
  ///
  /// In en, this message translates to:
  /// **'Delivery or pickup'**
  String get ticketCategoryDelivery;

  /// No description provided for @ticketCategoryProduct.
  ///
  /// In en, this message translates to:
  /// **'A product'**
  String get ticketCategoryProduct;

  /// No description provided for @ticketCategoryAuction.
  ///
  /// In en, this message translates to:
  /// **'Auctions'**
  String get ticketCategoryAuction;

  /// No description provided for @ticketCategoryAccount.
  ///
  /// In en, this message translates to:
  /// **'My account'**
  String get ticketCategoryAccount;

  /// No description provided for @ticketCategoryOther.
  ///
  /// In en, this message translates to:
  /// **'Something else'**
  String get ticketCategoryOther;

  /// No description provided for @ticketSubject.
  ///
  /// In en, this message translates to:
  /// **'Subject'**
  String get ticketSubject;

  /// No description provided for @ticketMessage.
  ///
  /// In en, this message translates to:
  /// **'How can we help?'**
  String get ticketMessage;

  /// No description provided for @relatedOrder.
  ///
  /// In en, this message translates to:
  /// **'Related order'**
  String get relatedOrder;

  /// No description provided for @noRelatedOrder.
  ///
  /// In en, this message translates to:
  /// **'None'**
  String get noRelatedOrder;

  /// No description provided for @ticketCreated.
  ///
  /// In en, this message translates to:
  /// **'Request sent. We’ll reply here.'**
  String get ticketCreated;

  /// No description provided for @typeMessage.
  ///
  /// In en, this message translates to:
  /// **'Write a message…'**
  String get typeMessage;

  /// No description provided for @send.
  ///
  /// In en, this message translates to:
  /// **'Send'**
  String get send;

  /// No description provided for @ticketStatusOpen.
  ///
  /// In en, this message translates to:
  /// **'Open'**
  String get ticketStatusOpen;

  /// No description provided for @ticketStatusPending.
  ///
  /// In en, this message translates to:
  /// **'Awaiting reply'**
  String get ticketStatusPending;

  /// No description provided for @ticketStatusResolved.
  ///
  /// In en, this message translates to:
  /// **'Resolved'**
  String get ticketStatusResolved;

  /// No description provided for @ticketStatusClosed.
  ///
  /// In en, this message translates to:
  /// **'Closed'**
  String get ticketStatusClosed;

  /// No description provided for @supportTeam.
  ///
  /// In en, this message translates to:
  /// **'Al Bahar support'**
  String get supportTeam;

  /// No description provided for @you.
  ///
  /// In en, this message translates to:
  /// **'You'**
  String get you;

  /// No description provided for @settingsTitle.
  ///
  /// In en, this message translates to:
  /// **'Settings'**
  String get settingsTitle;

  /// No description provided for @language.
  ///
  /// In en, this message translates to:
  /// **'Language'**
  String get language;

  /// No description provided for @appearance.
  ///
  /// In en, this message translates to:
  /// **'Appearance'**
  String get appearance;

  /// No description provided for @themeSystem.
  ///
  /// In en, this message translates to:
  /// **'System'**
  String get themeSystem;

  /// No description provided for @themeLight.
  ///
  /// In en, this message translates to:
  /// **'Light'**
  String get themeLight;

  /// No description provided for @themeDark.
  ///
  /// In en, this message translates to:
  /// **'Dark'**
  String get themeDark;

  /// No description provided for @legal.
  ///
  /// In en, this message translates to:
  /// **'About & legal'**
  String get legal;

  /// No description provided for @termsOfService.
  ///
  /// In en, this message translates to:
  /// **'Terms of service'**
  String get termsOfService;

  /// No description provided for @privacyPolicy.
  ///
  /// In en, this message translates to:
  /// **'Privacy policy'**
  String get privacyPolicy;

  /// No description provided for @aboutUs.
  ///
  /// In en, this message translates to:
  /// **'About Al Bahar'**
  String get aboutUs;

  /// No description provided for @faq.
  ///
  /// In en, this message translates to:
  /// **'FAQ'**
  String get faq;

  /// No description provided for @returnsPolicy.
  ///
  /// In en, this message translates to:
  /// **'Returns policy'**
  String get returnsPolicy;

  /// No description provided for @contactUs.
  ///
  /// In en, this message translates to:
  /// **'Contact us'**
  String get contactUs;

  /// No description provided for @appVersion.
  ///
  /// In en, this message translates to:
  /// **'Version {version}'**
  String appVersion(String version);

  /// No description provided for @privacy.
  ///
  /// In en, this message translates to:
  /// **'Privacy'**
  String get privacy;

  /// No description provided for @deleteAccount.
  ///
  /// In en, this message translates to:
  /// **'Delete account'**
  String get deleteAccount;

  /// No description provided for @deleteAccountTitle.
  ///
  /// In en, this message translates to:
  /// **'Delete your account?'**
  String get deleteAccountTitle;

  /// No description provided for @deleteAccountWarning.
  ///
  /// In en, this message translates to:
  /// **'Your account will be scheduled for deletion after a 14-day grace period. Your personal data will then be anonymised; order and invoice records required by law are retained. You can cancel any time before then.'**
  String get deleteAccountWarning;

  /// No description provided for @deleteAccountReason.
  ///
  /// In en, this message translates to:
  /// **'Why are you leaving? (optional)'**
  String get deleteAccountReason;

  /// No description provided for @deleteAccountTypeToConfirm.
  ///
  /// In en, this message translates to:
  /// **'Type {word} to confirm'**
  String deleteAccountTypeToConfirm(String word);

  /// No description provided for @deleteWord.
  ///
  /// In en, this message translates to:
  /// **'DELETE'**
  String get deleteWord;

  /// No description provided for @deleteAccountRequested.
  ///
  /// In en, this message translates to:
  /// **'Deletion scheduled for {date}'**
  String deleteAccountRequested(String date);

  /// No description provided for @cancelDeletion.
  ///
  /// In en, this message translates to:
  /// **'Cancel deletion'**
  String get cancelDeletion;

  /// No description provided for @deletionCancelled.
  ///
  /// In en, this message translates to:
  /// **'Your account will not be deleted.'**
  String get deletionCancelled;

  /// No description provided for @logoutConfirm.
  ///
  /// In en, this message translates to:
  /// **'Sign out of Al Bahar on this device?'**
  String get logoutConfirm;

  /// No description provided for @logoutAllDevices.
  ///
  /// In en, this message translates to:
  /// **'Sign out of all devices'**
  String get logoutAllDevices;

  /// No description provided for @marketingConsentToggle.
  ///
  /// In en, this message translates to:
  /// **'Marketing messages'**
  String get marketingConsentToggle;

  /// No description provided for @paymentStatusPending.
  ///
  /// In en, this message translates to:
  /// **'Pending'**
  String get paymentStatusPending;

  /// No description provided for @paymentStatusPaid.
  ///
  /// In en, this message translates to:
  /// **'Paid'**
  String get paymentStatusPaid;

  /// No description provided for @paymentStatusFailed.
  ///
  /// In en, this message translates to:
  /// **'Failed'**
  String get paymentStatusFailed;

  /// No description provided for @paymentStatusRefunded.
  ///
  /// In en, this message translates to:
  /// **'Refunded'**
  String get paymentStatusRefunded;

  /// No description provided for @paymentMethodLabel.
  ///
  /// In en, this message translates to:
  /// **'{method}'**
  String paymentMethodLabel(String method);

  /// No description provided for @deliverySlotTitle.
  ///
  /// In en, this message translates to:
  /// **'Delivery time'**
  String get deliverySlotTitle;

  /// No description provided for @deliverySlotMoreDates.
  ///
  /// In en, this message translates to:
  /// **'More dates'**
  String get deliverySlotMoreDates;

  /// No description provided for @deliverySlotNoDates.
  ///
  /// In en, this message translates to:
  /// **'No delivery times in the next 7 days. Try “More dates”.'**
  String get deliverySlotNoDates;

  /// No description provided for @deliverySlotNoSlotsDay.
  ///
  /// In en, this message translates to:
  /// **'No delivery times on this day.'**
  String get deliverySlotNoSlotsDay;

  /// No description provided for @deliverySlotRequiredHint.
  ///
  /// In en, this message translates to:
  /// **'Choose a delivery time to place your order'**
  String get deliverySlotRequiredHint;

  /// No description provided for @deliverySlotRequiredError.
  ///
  /// In en, this message translates to:
  /// **'Please choose a delivery time for this order.'**
  String get deliverySlotRequiredError;

  /// No description provided for @deliverySlotUnavailableError.
  ///
  /// In en, this message translates to:
  /// **'That delivery time is no longer available. Please choose another.'**
  String get deliverySlotUnavailableError;

  /// No description provided for @deliverySlotSummary.
  ///
  /// In en, this message translates to:
  /// **'Delivery: {date}, {from} – {to}'**
  String deliverySlotSummary(String date, String from, String to);

  /// No description provided for @coloursTitle.
  ///
  /// In en, this message translates to:
  /// **'Colours'**
  String get coloursTitle;

  /// No description provided for @coloursHint.
  ///
  /// In en, this message translates to:
  /// **'Choose how many of each colour'**
  String get coloursHint;

  /// No description provided for @addItemsToBag.
  ///
  /// In en, this message translates to:
  /// **'Add {count} to bag'**
  String addItemsToBag(int count);

  /// No description provided for @colourOutOfStock.
  ///
  /// In en, this message translates to:
  /// **'Out of stock'**
  String get colourOutOfStock;

  /// No description provided for @pickAtLeastOne.
  ///
  /// In en, this message translates to:
  /// **'Pick a quantity for at least one colour'**
  String get pickAtLeastOne;
}

class _AppLocalizationsDelegate extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  Future<AppLocalizations> load(Locale locale) {
    return SynchronousFuture<AppLocalizations>(lookupAppLocalizations(locale));
  }

  @override
  bool isSupported(Locale locale) => <String>['ar', 'en'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

AppLocalizations lookupAppLocalizations(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'ar':
      return AppLocalizationsAr();
    case 'en':
      return AppLocalizationsEn();
  }

  throw FlutterError(
    'AppLocalizations.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
