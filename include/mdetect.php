<?php

/* *******************************************
// Copyright 2010-2015, Anthony Hand
//
//
// File version 2015.05.13 (May 13, 2015)
// Updates:
//	- Moved MobileESP to GitHub. https://github.com/ahand/mobileesp
//	- Opera Mobile/Mini browser has the same UA string on multiple platforms and doesn't differentiate phone vs. tablet.
//		- Removed DetectOperaAndroidPhone(). This method is no longer reliable.
//		- Removed DetectOperaAndroidTablet(). This method is no longer reliable.
//	- Added support for Windows Phone 10: variable and DetectWindowsPhone10()
//	- Updated DetectWindowsPhone() to include WP10.
//	- Added support for Firefox OS.
//		- A variable plus DetectFirefoxOS(), DetectFirefoxOSPhone(), DetectFirefoxOSTablet()
//		- NOTE: Firefox doesn't add UA tokens to definitively identify Firefox OS vs. their browsers on other mobile platforms.
//	- Added support for Sailfish OS. Not enough info to add a tablet detection method at this time.
//		- A variable plus DetectSailfish(), DetectSailfishPhone()
//	- Added support for Ubuntu Mobile OS.
//		- DetectUbuntu(), DetectUbuntuPhone(), DetectUbuntuTablet()
//	- Added support for 2 smart TV OSes. They lack browsers but do have WebViews for use by HTML apps.
//		- One variable for Samsung Tizen TVs, plus DetectTizenTV()
//		- One variable for LG WebOS TVs, plus DetectWebOSTV()
//	- Updated DetectTizen(). Now tests for “mobile” to disambiguate from Samsung Smart TVs
//	- Removed variables for obsolete devices: deviceHtcFlyer, deviceXoom.
//	- Updated DetectAndroid(). No longer has a special test case for the HTC Flyer tablet.
//	- Updated DetectAndroidPhone().
//		- Updated internal detection code for Android.
//		- No longer has a special test case for the HTC Flyer tablet.
//		- Checks against DetectOperaMobile() on Android and reports here if relevant.
//	- Updated DetectAndroidTablet().
//		- No longer has a special test case for the HTC Flyer tablet.
//		- Checks against DetectOperaMobile() on Android to exclude it from here.
//	- DetectMeego(): Changed definition for this method. Now detects any Meego OS device, not just phones.
//	- DetectMeegoPhone(): NEW. For Meego phones. Ought to detect Opera browsers on Meego, as well.
//	- DetectTierIphone(): Added support for phones running Sailfish, Ubuntu and Firefox Mobile.
//	- DetectTierTablet(): Added support for tablets running Ubuntu and Firefox Mobile.
//	- DetectSmartphone(): Added support for Meego phones.
//	- Reorganized DetectMobileQuick(). Moved the following to DetectMobileLong():
//		- DetectDangerHiptop(), DetectMaemoTablet(), DetectSonyMylo(), DetectArchos()
//	- Removed the variable for Obigo, an embedded browser. The browser is on old devices.
//		- Couldn’t find info on current Obigo embedded browser user agent strings.
//
//
//
// LICENSE INFORMATION
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//        http://www.apache.org/licenses/LICENSE-2.0
// Unless required by applicable law or agreed to in writing,
// software distributed under the License is distributed on an
// "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND,
// either express or implied. See the License for the specific
// language governing permissions and limitations under the License.
//
//
// ABOUT THIS PROJECT
//   Project Owner: Anthony Hand
//   Email: anthony.hand@gmail.com
//   Web Site: http://www.mobileesp.com
//   Source Files: https://github.com/ahand/mobileesp
//
//   Versions of this code are available for:
//      PHP, JavaScript, Java, ASP.NET (C#), and Ruby
//
// *******************************************
*/



//**************************
// The uagent_info class encapsulates information about
//   a browser's connection to your web site.
//   You can use it to find out whether the browser asking for
//   your site's content is probably running on a mobile device.
//   The methods were written so you can be as granular as you want.
//   For example, enquiring whether it's as specific as an iPod Touch or
//   as general as a smartphone class device.
//   The object's methods return 1 for true, or 0 for false.
class uagent_info
{
    public $useragent = '';
    public $httpaccept = '';

    //standardized values for true and false.
    public $true = 1;
    public $false = 0;

    //Let's store values for quickly accessing the same info multiple times. InitCompleted
    public $initCompleted = 0; //Stores whether we're currently initializing the most popular functions.
    public $isWebkit = 0; //Stores the result of DetectWebkit()
    public $isMobilePhone = 0; //Stores the result of DetectMobileQuick()
    public $isIphone = 0; //Stores the result of DetectIphone()
    public $isAndroid = 0; //Stores the result of DetectAndroid()
    public $isAndroidPhone = 0; //Stores the result of DetectAndroidPhone()
    public $isTierTablet = 0; //Stores the result of DetectTierTablet()
    public $isTierIphone = 0; //Stores the result of DetectTierIphone()
    public $isTierRichCss = 0; //Stores the result of DetectTierRichCss()
    public $isTierGenericMobile = 0; //Stores the result of DetectTierOtherPhones()

    //Initialize some initial smartphone string variables.
    public $engineWebKit = 'webkit';
    public $deviceIphone = 'iphone';
    public $deviceIpod = 'ipod';
    public $deviceIpad = 'ipad';
    public $deviceMacPpc = 'macintosh'; //Used for disambiguation

    public $deviceAndroid = 'android';
    public $deviceGoogleTV = 'googletv';

    public $deviceWinPhone7 = 'windows phone os 7';
    public $deviceWinPhone8 = 'windows phone 8';
    public $deviceWinPhone10 = 'windows phone 10';
    public $deviceWinMob = 'windows ce';
    public $deviceWindows = 'windows';
    public $deviceIeMob = 'iemobile';
    public $devicePpc = 'ppc'; //Stands for PocketPC
    public $enginePie = 'wm5 pie'; //An old Windows Mobile

    public $deviceBB = 'blackberry';
    public $deviceBB10 = 'bb10'; //For the new BB 10 OS
    public $vndRIM = 'vnd.rim'; //Detectable when BB devices emulate IE or Firefox
    public $deviceBBStorm = 'blackberry95';  //Storm 1 and 2
    public $deviceBBBold = 'blackberry97'; //Bold 97x0 (non-touch)
    public $deviceBBBoldTouch = 'blackberry 99'; //Bold 99x0 (touchscreen)
    public $deviceBBTour = 'blackberry96'; //Tour
    public $deviceBBCurve = 'blackberry89'; //Curve2
    public $deviceBBCurveTouch = 'blackberry 938'; //Curve Touch
    public $deviceBBTorch = 'blackberry 98'; //Torch
    public $deviceBBPlaybook = 'playbook'; //PlayBook tablet

    public $deviceSymbian = 'symbian';
    public $deviceS60 = 'series60';
    public $deviceS70 = 'series70';
    public $deviceS80 = 'series80';
    public $deviceS90 = 'series90';

    public $devicePalm = 'palm';
    public $deviceWebOS = 'webos'; //For Palm devices
    public $deviceWebOStv = 'web0s'; //For LG TVs
    public $deviceWebOShp = 'hpwos'; //For HP's line of WebOS devices

    public $deviceNuvifone = 'nuvifone'; //Garmin Nuvifone
    public $deviceBada = 'bada'; //Samsung's Bada OS
    public $deviceTizen = 'tizen'; //Tizen OS
    public $deviceMeego = 'meego'; //Meego OS
    public $deviceSailfish = 'sailfish'; //Sailfish OS
    public $deviceUbuntu = 'ubuntu'; //Ubuntu Mobile OS

    public $deviceKindle = 'kindle'; //Amazon Kindle, eInk one
    public $engineSilk = 'silk-accelerated'; //Amazon's accelerated Silk browser for Kindle Fire

    public $engineBlazer = 'blazer'; //Old Palm browser
    public $engineXiino = 'xiino'; //Another old Palm

    //Initialize variables for mobile-specific content.
    public $vndwap = 'vnd.wap';
    public $wml = 'wml';

    //Initialize variables for other random devices and mobile browsers.
    public $deviceTablet = 'tablet'; //Generic term for slate and tablet devices
    public $deviceBrew = 'brew';
    public $deviceDanger = 'danger';
    public $deviceHiptop = 'hiptop';
    public $devicePlaystation = 'playstation';
    public $devicePlaystationVita = 'vita';
    public $deviceNintendoDs = 'nitro';
    public $deviceNintendo = 'nintendo';
    public $deviceWii = 'wii';
    public $deviceXbox = 'xbox';
    public $deviceArchos = 'archos';

    public $engineFirefox = 'firefox'; //For Firefox OS
    public $engineOpera = 'opera'; //Popular browser
    public $engineNetfront = 'netfront'; //Common embedded OS browser
    public $engineUpBrowser = 'up.browser'; //common on some phones
    public $engineOpenWeb = 'openweb'; //Transcoding by OpenWave server
    public $deviceMidp = 'midp'; //a mobile Java technology
    public $uplink = 'up.link';
    public $engineTelecaQ = 'teleca q'; //a modern feature phone browser

    public $devicePda = 'pda'; //some devices report themselves as PDAs
    public $mini = 'mini';  //Some mobile browsers put 'mini' in their names.
    public $mobile = 'mobile'; //Some mobile browsers put 'mobile' in their user agent strings.
    public $mobi = 'mobi'; //Some mobile browsers put 'mobi' in their user agent strings.

    //Smart TV strings
    public $smartTV1 = 'smart-tv'; //Samsung Tizen smart TVs
    public $smartTV2 = 'smarttv'; //LG WebOS smart TVs

    //Use Maemo, Tablet, and Linux to test for Nokia's Internet Tablets.
    public $maemo = 'maemo';
    public $linux = 'linux';
    public $qtembedded = 'qt embedded'; //for Sony Mylo and others
    public $mylocom2 = 'com2'; //for Sony Mylo also

    //In some UserAgents, the only clue is the manufacturer.
    public $manuSonyEricsson = 'sonyericsson';
    public $manuericsson = 'ericsson';
    public $manuSamsung1 = 'sec-sgh';
    public $manuSony = 'sony';
    public $manuHtc = 'htc'; //Popular Android and WinMo manufacturer

    //In some UserAgents, the only clue is the operator.
    public $svcDocomo = 'docomo';
    public $svcKddi = 'kddi';
    public $svcVodafone = 'vodafone';

    //Disambiguation strings.
    public $disUpdate = 'update'; //pda vs. update


    //**************************
    //The constructor. Allows the latest PHP (5.0+) to locate a constructor object and initialize the object.
    public function __construct()
    {
        $this->uagent_info();
    }


    //**************************
    //The object initializer. Initializes several default variables.
    public function uagent_info()
    {
        $this->useragent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
        $this->httpaccept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower($_SERVER['HTTP_ACCEPT']) : '';

        //Let's initialize some values to save cycles later.
        $this->InitDeviceScan();
    }

    //**************************
    // Initialize Key Stored Values.
    public function InitDeviceScan()
    {
        //Save these properties to speed processing
        global $isWebkit, $isIphone, $isAndroid, $isAndroidPhone;
        $this->isWebkit = $this->DetectWebkit();
        $this->isIphone = $this->DetectIphone();
        $this->isAndroid = $this->DetectAndroid();
        $this->isAndroidPhone = $this->DetectAndroidPhone();

        //These tiers are the most useful for web development
        global $isMobilePhone, $isTierTablet, $isTierIphone;
        $this->isMobilePhone = $this->DetectMobileQuick();
        $this->isTierIphone = $this->DetectTierIphone();
        $this->isTierTablet = $this->DetectTierTablet();

        //Optional: Comment these out if you NEVER use them.
        global $isTierRichCss, $isTierGenericMobile;
        $this->isTierRichCss = $this->DetectTierRichCss();
        $this->isTierGenericMobile = $this->DetectTierOtherPhones();

        $this->initCompleted = $this->true;
    }

    //**************************
    //Returns the contents of the User Agent value, in lower case.
    public function Get_Uagent()
    {
        return $this->useragent;
    }

    //**************************
    //Returns the contents of the HTTP Accept value, in lower case.
    public function Get_HttpAccept()
    {
        return $this->httpaccept;
    }


    //*****************************
    // Start device detection
    //*****************************

    //**************************
    // Detects if the current device is an iPhone.
    public function DetectIphone()
    {
        if ($this->initCompleted == $this->true ||
            $this->isIphone == $this->true) {
            return $this->isIphone;
        }

        if (stripos($this->useragent, (string) $this->deviceIphone) > -1) {
            //The iPad and iPod Touch say they're an iPhone. So let's disambiguate.
            if ($this->DetectIpad() == $this->true ||
                $this->DetectIpod() == $this->true) {
                return $this->false;
            }
            //Yay! It's an iPhone!
            else {
                return $this->true;
            }
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is an iPod Touch.
    public function DetectIpod()
    {
        if (stripos($this->useragent, (string) $this->deviceIpod) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is an iPad tablet.
    public function DetectIpad()
    {
        if (stripos($this->useragent, (string) $this->deviceIpad) > -1 &&
            $this->DetectWebkit() == $this->true) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is an iPhone or iPod Touch.
    public function DetectIphoneOrIpod()
    {
        //We repeat the searches here because some iPods may report themselves as an iPhone, which would be okay.
        if ($this->DetectIphone() == $this->true ||
               $this->DetectIpod() == $this->true) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects *any* iOS device: iPhone, iPod Touch, iPad.
    public function DetectIos()
    {
        if (($this->DetectIphoneOrIpod() == $this->true) ||
          ($this->DetectIpad() == $this->true)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }


    //**************************
    // Detects *any* Android OS-based device: phone, tablet, and multi-media player.
    // Also detects Google TV.
    public function DetectAndroid()
    {
        if ($this->initCompleted == $this->true ||
            $this->isAndroid == $this->true) {
            return $this->isAndroid;
        }

        if ((stripos($this->useragent, (string) $this->deviceAndroid) > -1)
            || ($this->DetectGoogleTV() == $this->true)) {
            return $this->true;
        }

        return $this->false;
    }

    //**************************
    // Detects if the current device is a (small-ish) Android OS-based device
    // used for calling and/or multi-media (like a Samsung Galaxy Player).
    // Google says these devices will have 'Android' AND 'mobile' in user agent.
    // Ignores tablets (Honeycomb and later).
    public function DetectAndroidPhone()
    {
        if ($this->initCompleted == $this->true ||
            $this->isAndroidPhone == $this->true) {
            return $this->isAndroidPhone;
        }

        //First, let's make sure we're on an Android device.
        if ($this->DetectAndroid() == $this->false) {
            return $this->false;
        }

        //If it's Android and has 'mobile' in it, Google says it's a phone.
        if (stripos($this->useragent, (string) $this->mobile) > -1) {
            return $this->true;
        }

        //Special check for Android devices with Opera Mobile/Mini. They should report here.
        if (($this->DetectOperaMobile() == $this->true)) {
            return $this->true;
        }

        return $this->false;
    }

    //**************************
    // Detects if the current device is a (self-reported) Android tablet.
    // Google says these devices will have 'Android' and NOT 'mobile' in their user agent.
    public function DetectAndroidTablet()
    {
        //First, let's make sure we're on an Android device.
        if ($this->DetectAndroid() == $this->false) {
            return $this->false;
        }

        //Special check for Android devices with Opera Mobile/Mini. They should NOT report here.
        if ($this->DetectOperaMobile() == $this->true) {
            return $this->false;
        }

        //Otherwise, if it's Android and does NOT have 'mobile' in it, Google says it's a tablet.
        if (stripos($this->useragent, (string) $this->mobile) > -1) {
            return $this->false;
        } else {
            return $this->true;
        }
    }

    //**************************
    // Detects if the current device is an Android OS-based device and
    //   the browser is based on WebKit.
    public function DetectAndroidWebKit()
    {
        if (($this->DetectAndroid() == $this->true) &&
          ($this->DetectWebkit() == $this->true)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is a GoogleTV.
    public function DetectGoogleTV()
    {
        if (stripos($this->useragent, (string) $this->deviceGoogleTV) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is based on WebKit.
    public function DetectWebkit()
    {
        if ($this->initCompleted == $this->true ||
            $this->isWebkit == $this->true) {
            return $this->isWebkit;
        }

        if (stripos($this->useragent, (string) $this->engineWebKit) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }


    //**************************
    // Detects if the current browser is a
    // Windows Phone 7, 8, or 10 device.
    public function DetectWindowsPhone()
    {
        if (($this->DetectWindowsPhone7() == $this->true)
              || ($this->DetectWindowsPhone8() == $this->true)
              || ($this->DetectWindowsPhone10() == $this->true)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects a Windows Phone 7 device (in mobile browsing mode).
    public function DetectWindowsPhone7()
    {
        if (stripos($this->useragent, (string) $this->deviceWinPhone7) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects a Windows Phone 8 device (in mobile browsing mode).
    public function DetectWindowsPhone8()
    {
        if (stripos($this->useragent, (string) $this->deviceWinPhone8) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects a Windows Phone 10 device (in mobile browsing mode).
    public function DetectWindowsPhone10()
    {
        if (stripos($this->useragent, (string) $this->deviceWinPhone10) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is a Windows Mobile device.
    // Excludes Windows Phone 7 and later devices.
    // Focuses on Windows Mobile 6.xx and earlier.
    public function DetectWindowsMobile()
    {
        if ($this->DetectWindowsPhone() == $this->true) {
            return $this->false;
        }

        //Most devices use 'Windows CE', but some report 'iemobile'
        //  and some older ones report as 'PIE' for Pocket IE.
        if (stripos($this->useragent, (string) $this->deviceWinMob) > -1 ||
            stripos($this->useragent, (string) $this->deviceIeMob) > -1 ||
            stripos($this->useragent, (string) $this->enginePie) > -1) {
            return $this->true;
        }
        //Test for Windows Mobile PPC but not old Macintosh PowerPC.
        if (stripos($this->useragent, (string) $this->devicePpc) > -1
            && !(stripos($this->useragent, (string) $this->deviceMacPpc) > 1)) {
            return $this->true;
        }
        //Test for certain Windwos Mobile-based HTC devices.
        if (stripos($this->useragent, (string) $this->manuHtc) > -1 &&
            stripos($this->useragent, (string) $this->deviceWindows) > -1) {
            return $this->true;
        }
        if ($this->DetectWapWml() == $this->true &&
            stripos($this->useragent, (string) $this->deviceWindows) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is any BlackBerry device.
    // Includes BB10 OS, but excludes the PlayBook.
    public function DetectBlackBerry()
    {
        if ((stripos($this->useragent, (string) $this->deviceBB) > -1) ||
           (stripos($this->httpaccept, (string) $this->vndRIM) > -1)) {
            return $this->true;
        }
        if ($this->DetectBlackBerry10Phone() == $this->true) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is a BlackBerry 10 OS phone.
    // Excludes tablets.
    public function DetectBlackBerry10Phone()
    {
        if ((stripos($this->useragent, (string) $this->deviceBB10) > -1) &&
           (stripos($this->useragent, (string) $this->mobile) > -1)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is on a BlackBerry tablet device.
    //    Examples: PlayBook
    public function DetectBlackBerryTablet()
    {
        if ((stripos($this->useragent, (string) $this->deviceBBPlaybook) > -1)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is a BlackBerry phone device AND uses a
    //    WebKit-based browser. These are signatures for the new BlackBerry OS 6.
    //    Examples: Torch. Includes the Playbook.
    public function DetectBlackBerryWebKit()
    {
        if (($this->DetectBlackBerry() == $this->true) &&
          ($this->DetectWebkit() == $this->true)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is a BlackBerry Touch phone device with
    //    a large screen, such as the Storm, Torch, and Bold Touch. Excludes the Playbook.
    public function DetectBlackBerryTouch()
    {
        if ((stripos($this->useragent, (string) $this->deviceBBStorm) > -1) ||
         (stripos($this->useragent, (string) $this->deviceBBTorch) > -1) ||
         (stripos($this->useragent, (string) $this->deviceBBBoldTouch) > -1) ||
         (stripos($this->useragent, (string) $this->deviceBBCurveTouch) > -1)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is a BlackBerry OS 5 device AND
    //    has a more capable recent browser. Excludes the Playbook.
    //    Examples, Storm, Bold, Tour, Curve2
    //    Excludes the new BlackBerry OS 6 and 7 browser!!
    public function DetectBlackBerryHigh()
    {
        //Disambiguate for BlackBerry OS 6 or 7 (WebKit) browser
        if ($this->DetectBlackBerryWebKit() == $this->true) {
            return $this->false;
        }
        if ($this->DetectBlackBerry() == $this->true) {
            if (($this->DetectBlackBerryTouch() == $this->true) ||
              stripos($this->useragent, (string) $this->deviceBBBold) > -1 ||
              stripos($this->useragent, (string) $this->deviceBBTour) > -1 ||
              stripos($this->useragent, (string) $this->deviceBBCurve) > -1) {
                return $this->true;
            } else {
                return $this->false;
            }
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is a BlackBerry device AND
    //    has an older, less capable browser.
    //    Examples: Pearl, 8800, Curve1.
    public function DetectBlackBerryLow()
    {
        if ($this->DetectBlackBerry() == $this->true) {
            //Assume that if it's not in the High tier, then it's Low.
            if (($this->DetectBlackBerryHigh() == $this->true) ||
              ($this->DetectBlackBerryWebKit() == $this->true)) {
                return $this->false;
            } else {
                return $this->true;
            }
        } else {
            return $this->false;
        }
    }


    //**************************
    // Detects if the current browser is the Nokia S60 Open Source Browser.
    public function DetectS60OssBrowser()
    {
        //First, test for WebKit, then make sure it's either Symbian or S60.
        if ($this->DetectWebkit() == $this->true) {
            if (stripos($this->useragent, (string) $this->deviceSymbian) > -1 ||
                stripos($this->useragent, (string) $this->deviceS60) > -1) {
                return $this->true;
            } else {
                return $this->false;
            }
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is any Symbian OS-based device,
    //   including older S60, Series 70, Series 80, Series 90, and UIQ,
    //   or other browsers running on these devices.
    public function DetectSymbianOS()
    {
        if (stripos($this->useragent, (string) $this->deviceSymbian) > -1 ||
            stripos($this->useragent, (string) $this->deviceS60) > -1 ||
            stripos($this->useragent, (string) $this->deviceS70) > -1 ||
            stripos($this->useragent, (string) $this->deviceS80) > -1 ||
            stripos($this->useragent, (string) $this->deviceS90) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }


    //**************************
    // Detects if the current browser is on a PalmOS device.
    public function DetectPalmOS()
    {
        //Most devices nowadays report as 'Palm', but some older ones reported as Blazer or Xiino.
        if (stripos($this->useragent, (string) $this->devicePalm) > -1 ||
            stripos($this->useragent, (string) $this->engineBlazer) > -1 ||
            stripos($this->useragent, (string) $this->engineXiino) > -1) {
            //Make sure it's not WebOS first
            if ($this->DetectPalmWebOS() == $this->true) {
                return $this->false;
            } else {
                return $this->true;
            }
        } else {
            return $this->false;
        }
    }


    //**************************
    // Detects if the current browser is on a Palm device
    //   running the new WebOS.
    public function DetectPalmWebOS()
    {
        if (stripos($this->useragent, (string) $this->deviceWebOS) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is on an HP tablet running WebOS.
    public function DetectWebOSTablet()
    {
        if ((stripos($this->useragent, (string) $this->deviceWebOShp) > -1)
              && (stripos($this->useragent, (string) $this->deviceTablet) > -1)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is on a WebOS smart TV.
    public function DetectWebOSTV()
    {
        if ((stripos($this->useragent, (string) $this->deviceWebOStv) > -1)
              && (stripos($this->useragent, (string) $this->smartTV2) > -1)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }


    //**************************
    // Detects if the current browser is Opera Mobile or Mini.
    public function DetectOperaMobile()
    {
        if ((stripos($this->useragent, (string) $this->engineOpera) > -1) &&
        ((stripos($this->useragent, (string) $this->mini) > -1) ||
            (stripos($this->useragent, (string) $this->mobi) > -1))) {
            return $this->true;
        }

        return $this->false;
    }

    //**************************
    // Detects if the current device is an Amazon Kindle (eInk devices only).
    // Note: For the Kindle Fire, use the normal Android methods.
    public function DetectKindle()
    {
        if (stripos($this->useragent, (string) $this->deviceKindle) > -1 &&
            $this->DetectAndroid() == $this->false) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current Amazon device has turned on the Silk accelerated browsing feature.
    // Note: Typically used by the the Kindle Fire.
    public function DetectAmazonSilk()
    {
        if (stripos($this->useragent, (string) $this->engineSilk) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if a Garmin Nuvifone device.
    public function DetectGarminNuvifone()
    {
        if (stripos($this->useragent, (string) $this->deviceNuvifone) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects a device running the Bada OS from Samsung.
    public function DetectBada()
    {
        if (stripos($this->useragent, (string) $this->deviceBada) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects a device running the Tizen smartphone OS.
    public function DetectTizen()
    {
        if ((stripos($this->useragent, (string) $this->deviceTizen) > -1)
              && (stripos($this->useragent, (string) $this->mobile) > -1)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is on a Tizen smart TV.
    public function DetectTizenTV()
    {
        if ((stripos($this->useragent, (string) $this->deviceTizen) > -1)
              && (stripos($this->useragent, (string) $this->smartTV1) > -1)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects a device running the Meego OS.
    public function DetectMeego()
    {
        if (stripos($this->useragent, (string) $this->deviceMeego) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects a phone running the Meego OS.
    public function DetectMeegoPhone()
    {
        if ((stripos($this->useragent, (string) $this->deviceMeego) > -1)
              && (stripos($this->useragent, (string) $this->mobi) > -1)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects a mobile device (probably) running the Firefox OS.
    public function DetectFirefoxOS()
    {
        if (($this->DetectFirefoxOSPhone() == $this->true)
          || ($this->DetectFirefoxOSTablet() == $this->true)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects a phone (probably) running the Firefox OS.
    public function DetectFirefoxOSPhone()
    {
        //First, let's make sure we're NOT on another major mobile OS.
        if ($this->DetectIos() == $this->true
          || $this->DetectAndroid() == $this->true
          || $this->DetectSailfish() == $this->true) {
            return $this->false;
        }

        if ((stripos($this->useragent, (string) $this->engineFirefox) > -1) &&
          (stripos($this->useragent, (string) $this->mobile) > -1)) {
            return $this->true;
        }

        return $this->false;
    }

    //**************************
    // Detects a tablet (probably) running the Firefox OS.
    public function DetectFirefoxOSTablet()
    {
        //First, let's make sure we're NOT on another major mobile OS.
        if ($this->DetectIos() == $this->true
          || $this->DetectAndroid() == $this->true
          || $this->DetectSailfish() == $this->true) {
            return $this->false;
        }

        if ((stripos($this->useragent, (string) $this->engineFirefox) > -1) &&
          (stripos($this->useragent, (string) $this->deviceTablet) > -1)) {
            return $this->true;
        }

        return $this->false;
    }

    //**************************
    // Detects a device running the Sailfish OS.
    public function DetectSailfish()
    {
        if (stripos($this->useragent, (string) $this->deviceSailfish) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects a phone running the Sailfish OS.
    public function DetectSailfishPhone()
    {
        if (($this->DetectSailfish() == $this->true) &&
          (stripos($this->useragent, (string) $this->mobile) > -1)) {
            return $this->true;
        }

        return $this->false;
    }

    //**************************
    // Detects a mobile device running the Ubuntu Mobile OS.
    public function DetectUbuntu()
    {
        if (($this->DetectUbuntuPhone() == $this->true)
          || ($this->DetectUbuntuTablet() == $this->true)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects a phone running the Ubuntu Mobile OS.
    public function DetectUbuntuPhone()
    {
        if ((stripos($this->useragent, (string) $this->deviceUbuntu) > -1) &&
          (stripos($this->useragent, (string) $this->mobile) > -1)) {
            return $this->true;
        }

        return $this->false;
    }

    //**************************
    // Detects a tablet running the Ubuntu Mobile OS.
    public function DetectUbuntuTablet()
    {
        if ((stripos($this->useragent, (string) $this->deviceUbuntu) > -1) &&
          (stripos($this->useragent, (string) $this->deviceTablet) > -1)) {
            return $this->true;
        }

        return $this->false;
    }

    //**************************
    // Detects the Danger Hiptop device.
    public function DetectDangerHiptop()
    {
        if (stripos($this->useragent, (string) $this->deviceDanger) > -1 ||
            stripos($this->useragent, (string) $this->deviceHiptop) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current browser is a Sony Mylo device.
    public function DetectSonyMylo()
    {
        if ((stripos($this->useragent, (string) $this->manuSony) > -1) &&
           ((stripos($this->useragent, (string) $this->qtembedded) > -1) ||
            (stripos($this->useragent, (string) $this->mylocom2) > -1))) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is on one of the Maemo-based Nokia Internet Tablets.
    public function DetectMaemoTablet()
    {
        if (stripos($this->useragent, (string) $this->maemo) > -1) {
            return $this->true;
        }
        //For Nokia N810, must be Linux + Tablet, or else it could be something else.
        if ((stripos($this->useragent, (string) $this->linux) > -1)
          && (stripos($this->useragent, (string) $this->deviceTablet) > -1)
          && ($this->DetectWebOSTablet() == $this->false)
          && ($this->DetectAndroid() == $this->false)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is an Archos media player/Internet tablet.
    public function DetectArchos()
    {
        if (stripos($this->useragent, (string) $this->deviceArchos) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is an Internet-capable game console.
    // Includes many handheld consoles.
    public function DetectGameConsole()
    {
        if ($this->DetectSonyPlaystation() == $this->true) {
            return $this->true;
        } elseif ($this->DetectNintendo() == $this->true) {
            return $this->true;
        } elseif ($this->DetectXbox() == $this->true) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is a Sony Playstation.
    public function DetectSonyPlaystation()
    {
        if (stripos($this->useragent, (string) $this->devicePlaystation) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is a handheld gaming device with
    // a touchscreen and modern iPhone-class browser. Includes the Playstation Vita.
    public function DetectGamingHandheld()
    {
        if ((stripos($this->useragent, (string) $this->devicePlaystation) > -1) &&
           (stripos($this->useragent, (string) $this->devicePlaystationVita) > -1)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is a Nintendo game device.
    public function DetectNintendo()
    {
        if (stripos($this->useragent, (string) $this->deviceNintendo) > -1 ||
             stripos($this->useragent, (string) $this->deviceWii) > -1 ||
             stripos($this->useragent, (string) $this->deviceNintendoDs) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device is a Microsoft Xbox.
    public function DetectXbox()
    {
        if (stripos($this->useragent, (string) $this->deviceXbox) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects whether the device is a Brew-powered device.
    public function DetectBrewDevice()
    {
        if (stripos($this->useragent, (string) $this->deviceBrew) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects whether the device supports WAP or WML.
    public function DetectWapWml()
    {
        if (stripos($this->httpaccept, (string) $this->vndwap) > -1 ||
            stripos($this->httpaccept, (string) $this->wml) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // Detects if the current device supports MIDP, a mobile Java technology.
    public function DetectMidpCapable()
    {
        if (stripos($this->useragent, (string) $this->deviceMidp) > -1 ||
            stripos($this->httpaccept, (string) $this->deviceMidp) > -1) {
            return $this->true;
        } else {
            return $this->false;
        }
    }



    //*****************************
    // Device Classes
    //*****************************

    //**************************
    // Check to see whether the device is *any* 'smartphone'.
    //   Note: It's better to use DetectTierIphone() for modern touchscreen devices.
    public function DetectSmartphone()
    {
        //Exclude duplicates from TierIphone
        if (($this->DetectTierIphone() == $this->true)
          || ($this->DetectS60OssBrowser() == $this->true)
          || ($this->DetectSymbianOS() == $this->true)
          || ($this->DetectWindowsMobile() == $this->true)
          || ($this->DetectBlackBerry() == $this->true)
          || ($this->DetectMeegoPhone() == $this->true)
          || ($this->DetectPalmWebOS() == $this->true)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // The quick way to detect for a mobile device.
    //   Will probably detect most recent/current mid-tier Feature Phones
    //   as well as smartphone-class devices. Excludes Apple iPads and other modern tablets.
    public function DetectMobileQuick()
    {
        if ($this->initCompleted == $this->true ||
            $this->isMobilePhone == $this->true) {
            return $this->isMobilePhone;
        }

        //Let's exclude tablets
        if ($this->isTierTablet == $this->true) {
            return $this->false;
        }

        //Most mobile browsing is done on smartphones
        if ($this->DetectSmartphone() == $this->true) {
            return $this->true;
        }

        //Catch-all for many mobile devices
        if (stripos($this->useragent, (string) $this->mobile) > -1) {
            return $this->true;
        }

        if ($this->DetectOperaMobile() == $this->true) {
            return $this->true;
        }

        //We also look for Kindle devices
        if ($this->DetectKindle() == $this->true ||
           $this->DetectAmazonSilk() == $this->true) {
            return $this->true;
        }

        if (($this->DetectWapWml() == $this->true)
                || ($this->DetectMidpCapable() == $this->true)
              || ($this->DetectBrewDevice() == $this->true)) {
            return $this->true;
        }

        if ((stripos($this->useragent, (string) $this->engineNetfront) > -1)
              || (stripos($this->useragent, (string) $this->engineUpBrowser) > -1)) {
            return $this->true;
        }

        return $this->false;
    }

    //**************************
    // The longer and more thorough way to detect for a mobile device.
    //   Will probably detect most feature phones,
    //   smartphone-class devices, Internet Tablets,
    //   Internet-enabled game consoles, etc.
    //   This ought to catch a lot of the more obscure and older devices, also --
    //   but no promises on thoroughness!
    public function DetectMobileLong()
    {
        if ($this->DetectMobileQuick() == $this->true) {
            return $this->true;
        }
        if ($this->DetectGameConsole() == $this->true) {
            return $this->true;
        }

        if (($this->DetectDangerHiptop() == $this->true)
              || ($this->DetectMaemoTablet() == $this->true)
              || ($this->DetectSonyMylo() == $this->true)
              || ($this->DetectArchos() == $this->true)) {
            return $this->true;
        }

        if ((stripos($this->useragent, (string) $this->devicePda) > -1) &&
          !(stripos($this->useragent, (string) $this->disUpdate) > -1)) {
            return $this->true;
        }

        //Detect older phones from certain manufacturers and operators.
        if ((stripos($this->useragent, (string) $this->uplink) > -1)
             || (stripos($this->useragent, (string) $this->engineOpenWeb) > -1)
                || (stripos($this->useragent, (string) $this->manuSamsung1) > -1)
                || (stripos($this->useragent, (string) $this->manuSonyEricsson) > -1)
                || (stripos($this->useragent, (string) $this->manuericsson) > -1)
                || (stripos($this->useragent, (string) $this->svcDocomo) > -1)
                || (stripos($this->useragent, (string) $this->svcKddi) > -1)
                || (stripos($this->useragent, (string) $this->svcVodafone) > -1)) {
            return $this->true;
        }

        return $this->false;
    }


    //*****************************
    // For Mobile Web Site Design
    //*****************************

    //**************************
    // The quick way to detect for a tier of devices.
    //   This method detects for the new generation of
    //   HTML 5 capable, larger screen tablets.
    //   Includes iPad, Android (e.g., Xoom), BB Playbook, WebOS, etc.
    public function DetectTierTablet()
    {
        if ($this->initCompleted == $this->true ||
            $this->isTierTablet == $this->true) {
            return $this->isTierTablet;
        }

        if (($this->DetectIpad() == $this->true)
           || ($this->DetectAndroidTablet() == $this->true)
           || ($this->DetectBlackBerryTablet() == $this->true)
           || ($this->DetectFirefoxOSTablet() == $this->true)
           || ($this->DetectUbuntuTablet() == $this->true)
           || ($this->DetectWebOSTablet() == $this->true)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }


    //**************************
    // The quick way to detect for a tier of devices.
    //   This method detects for devices which can
    //   display iPhone-optimized web content.
    //   Includes iPhone, iPod Touch, Android, Windows Phone, BB10, Playstation Vita, etc.
    public function DetectTierIphone()
    {
        if ($this->initCompleted == $this->true ||
            $this->isTierIphone == $this->true) {
            return $this->isTierIphone;
        }

        if (($this->DetectIphoneOrIpod() == $this->true)
              || ($this->DetectAndroidPhone() == $this->true)
              || ($this->DetectWindowsPhone() == $this->true)
              || ($this->DetectBlackBerry10Phone() == $this->true)
              || ($this->DetectPalmWebOS() == $this->true)
              || ($this->DetectBada() == $this->true)
              || ($this->DetectTizen() == $this->true)
              || ($this->DetectFirefoxOSPhone() == $this->true)
              || ($this->DetectSailfishPhone() == $this->true)
              || ($this->DetectUbuntuPhone() == $this->true)
              || ($this->DetectGamingHandheld() == $this->true)) {
            return $this->true;
        }

        //Note: BB10 phone is in the previous paragraph
        if (($this->DetectBlackBerryWebKit() == $this->true) &&
          ($this->DetectBlackBerryTouch() == $this->true)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }

    //**************************
    // The quick way to detect for a tier of devices.
    //   This method detects for devices which are likely to be capable
    //   of viewing CSS content optimized for the iPhone,
    //   but may not necessarily support JavaScript.
    //   Excludes all iPhone Tier devices.
    public function DetectTierRichCss()
    {
        if ($this->initCompleted == $this->true ||
            $this->isTierRichCss == $this->true) {
            return $this->isTierRichCss;
        }

        if ($this->DetectMobileQuick() == $this->true) {
            //Exclude iPhone Tier and e-Ink Kindle devices
            if (($this->DetectTierIphone() == $this->true) ||
                ($this->DetectKindle() == $this->true)) {
                return $this->false;
            }

            //The following devices are explicitly ok.
            if ($this->DetectWebkit() == $this->true) { //Any WebKit
                return $this->true;
            }
            if ($this->DetectS60OssBrowser() == $this->true) {
                return $this->true;
            }

            //Note: 'High' BlackBerry devices ONLY
            if ($this->DetectBlackBerryHigh() == $this->true) {
                return $this->true;
            }

            //Older Windows 'Mobile' isn't good enough for iPhone Tier.
            if ($this->DetectWindowsMobile() == $this->true) {
                return $this->true;
            }
            if (stripos($this->useragent, (string) $this->engineTelecaQ) > -1) {
                return $this->true;
            }

            //default
            else {
                return $this->false;
            }
        } else {
            return $this->false;
        }
    }

    //**************************
    // The quick way to detect for a tier of devices.
    //   This method detects for all other types of phones,
    //   but excludes the iPhone and RichCSS Tier devices.
    public function DetectTierOtherPhones()
    {
        if ($this->initCompleted == $this->true ||
            $this->isTierGenericMobile == $this->true) {
            return $this->isTierGenericMobile;
        }

        //Exclude devices in the other 2 categories
        if (($this->DetectMobileLong() == $this->true)
          && ($this->DetectTierIphone() == $this->false)
          && ($this->DetectTierRichCss() == $this->false)) {
            return $this->true;
        } else {
            return $this->false;
        }
    }


}


//Was informed by a MobileESP user that it's a best practice
//  to omit the closing ?&gt; marks here. They can sometimes
//  cause errors with HTML headers.
