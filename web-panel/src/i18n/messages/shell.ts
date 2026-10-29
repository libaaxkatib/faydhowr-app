// Messages for the shell area. Filled in during the Somali/English extension; every message is `proposed` (written for this implementation).
// Keys are `<category>.<area>.<name>`; the English text is exactly what the screen shows today.
//
// Covers the sign-in page, the account menu, the dashboard, the sidebar groups that are not Human Resources, the route-level "no access" / "not built"
// pages, the shared UI components' defaults and the default error messages of the API client. Dashboard, XARUN, PROJECT and CMS are not here on
// purpose: they stay as written (owner decision for Dashboard; XARUN and PROJECT are the owner's own terms; CMS is a technical term).

import { defineMessages, message } from '../catalog.ts';

export const shell = defineMessages({
  // Sidebar: group titles, the "Planned" badge, the module tooltip, the footer tagline and the breadcrumb root
  'nav.shell.marketing': message('Marketing', 'Suuqgeynta'),
  'nav.shell.mobileApp': message('Mobile App Management', 'Maamulka App-ka Mobaylka'),
  'nav.shell.system': message('System', 'Nidaamka'),
  'nav.shell.planned': message('Planned', 'La qorsheeyay'),
  'warning.shell.moduleNotBuilt': message("Backend for this module isn't built yet", 'Server-ka qaybtan weli lama dhisin'),
  'nav.shell.tagline': message('Service with trust', 'Adeeg kalsooni leh'),
  'nav.shell.home': message('Home', 'Guriga'),

  // Sidebar items of the Marketing, Mobile App and System groups (Employees and Reports reuse nav.employees and nav.reports)
  'nav.shell.followUps': message('Follow-ups', 'Daba-galka'),
  'nav.shell.teams': message('Teams', 'Kooxaha'),
  'nav.shell.commission': message('Commission', 'Komishanka'),
  'nav.shell.services': message('Services', 'Adeegyada'),
  'nav.shell.products': message('Products', 'Alaabta'),
  'nav.shell.bookings': message('Bookings', 'Ballamaha'),
  'nav.shell.quotations': message('Quotations', 'Qiimeynada'),
  'nav.shell.orders': message('Orders', 'Dalabyada'),
  'nav.shell.payments': message('Payments', 'Lacag-bixinnada'),
  'nav.shell.inventory': message('Inventory', 'Bakhaarka'),
  'nav.shell.reviews': message('Reviews', 'Dib-u-eegisyada'),
  'nav.shell.notifications': message('Notifications', 'Ogeysiisyada'),
  'nav.shell.accounting': message('Accounting', 'Xisaabaadka'),
  'nav.shell.adminUsers': message('Admin Users', 'Isticmaalayaasha Maamulka'),
  'nav.shell.rolesPermissions': message('Roles & Permissions', 'Doorarka & Ogolaanshaha'),
  'nav.shell.settings': message('Settings', 'Dejinta'),
  'nav.shell.translations': message('Translations', 'Tarjumaadaha'),
  'nav.shell.auditLog': message('Audit Log', 'Diiwaanka Hubinta'),
  'nav.shell.dataIssues': message('Data Issues & Reconciliation', 'Dhibaatooyinka Xogta & Isku-waafajinta'),

  // Account menu and sign-in
  'action.shell.signOut': message('Sign out', 'Ka bax'),
  'action.shell.signIn': message('Sign in', 'Gal'),
  'action.shell.changePassword': message('Change password', 'Bedel erayga sirta ah'),
  'action.shell.changePasswordSubmit': message('Change Password', 'Bedel Erayga Sirta ah'),
  'option.shell.signInTitle': message('Sign in to the Web Panel', 'Ku gal Guddiga Webka'),
  'option.shell.internalOnly': message('Fayadhowr Web Panel — internal use only', 'Guddiga Webka ee Fayadhowr — loogu talagalay isticmaalka gudaha oo keliya'),
  'option.shell.email': message('Email', 'Iimayl'),
  'option.shell.password': message('Password', 'Erayga sirta ah'),
  'option.shell.currentPassword': message('Current password', 'Erayga sirta ah ee hadda'),
  'option.shell.newPassword': message('New password', 'Erayga sirta ah ee cusub'),
  'option.shell.confirmNewPassword': message('Confirm new password', 'Xaqiiji erayga sirta ah ee cusub'),
  'option.shell.minEightChars': message('Minimum 8 characters.', 'Ugu yaraan 8 xaraf.'),
  'option.shell.checkingSession': message('Checking your session…', 'Fadhigaaga waa la hubinayaa…'),
  'notify.shell.somethingWrongTryAgain': message('Something went wrong. Please try again.', 'Wax qalad ah ayaa dhacay. Fadlan isku day mar kale.'),
  'notify.shell.somethingWrong': message('Something went wrong.', 'Wax qalad ah ayaa dhacay.'),
  'notify.shell.passwordChanged': message('Password changed. Your other devices have been signed out.', 'Erayga sirta ah waa la beddelay. Aaladahaaga kale waa laga saaray.'),
  'notify.shell.passwordChangeFailed': message('Could not change password.', 'Erayga sirta ah lama beddeli karin.'),

  // The role shown under the admin's name in the header (keyed by the stored role)
  'status.shell.role.super_admin': message('Super Admin', 'Maamule sare'),
  'status.shell.role.manager': message('manager', 'Maareeye'),
  'status.shell.role.sales': message('sales', 'Iibka'),
  'status.shell.role.inventory': message('inventory', 'Bakhaarka'),
  'status.shell.role.accountant': message('accountant', 'Xisaabiye'),
  'status.shell.role.hr_manager': message('HR Manager', 'Maareeyaha shaqaalaha'),
  'status.shell.role.hr_employee': message('HR Employee', 'Shaqaalaha HR'),
  'status.shell.role.marketing_manager': message('Marketing Manager', 'Maareeyaha suuqgeynta'),
  'status.shell.role.marketing_employee': message('Marketing Employee', 'Shaqaalaha suuqgeynta'),
  'status.shell.role.mobile_app_manager': message('Mobile App Manager', 'Maareeyaha App-ka Mobaylka'),

  // Route-level pages
  'warning.shell.accessRestricted': message('Access restricted', 'Gelitaanka waa la xaddiday'),
  'warning.shell.notBuiltTitle': message('Not built yet', 'Weli lama dhisin'),
  'warning.shell.notBuiltText': message(
    "This module is on the roadmap but hasn't been implemented in this phase. See the Web Panel Blueprint for the build order.",
    'Qaybtan waxay ku jirtaa qorshaha, laakiin weli lagama dhammaystirin marxaladdan. Eeg Qorshaha Guddiga Webka si aad u aragto kala horreynta dhismaha.',
  ),

  // Shared UI defaults
  'option.shell.searchPlaceholder': message('Search…', 'Raadi…'),
  'option.shell.nothingYet': message('Nothing here yet', 'Wax halkan ma jiraan weli'),

  // Default messages of the API client (shown when the server sends none)
  'notify.shell.requestFailed': message('Request failed with status {status}.', 'Codsigu wuu fashilmay, xaaladda: {status}.'),
  'notify.shell.unexpectedBinary': message('Expected a JSON envelope but received a binary response.', 'Waxaa la filayay jawaab JSON ah, laakiin waxaa la helay jawaab binary ah.'),

  // Dashboard
  'action.shell.refresh': message('Refresh', 'Cusboonaysii'),
  'option.shell.loadingDashboard': message('Loading dashboard…', 'Dashboard-ka waa la soo rarayaa…'),
  'option.shell.allTime': message('All time', 'Wakhti walba'),
  'option.shell.yesterday': message('Yesterday', 'Shalay'),
  'option.shell.last7Days': message('Last 7 days', '7-dii maalmood ee ugu dambeeyay'),
  'option.shell.last30Days': message('Last 30 days', '30-kii maalmood ee ugu dambeeyay'),
  'option.shell.thisMonthLower': message('This month', 'Bishan'),
  'option.shell.lastMonthLower': message('Last month', 'Bishii hore'),
  'option.shell.bookingOrderOverview': message('Booking & Order Overview', 'Dulmarka Ballamaha & Dalabyada'),
  'option.shell.recentActivities': message('Recent Activities', 'Hawlihii ugu dambeeyay'),
  'option.shell.latestOrders': message('Latest Orders', 'Dalabyadii ugu dambeeyay'),
  'option.shell.salesOverview': message('Sales Overview', 'Dulmarka Iibka'),
  'warning.shell.trendTitle': message('Trend chart not available yet', 'Garaafka isbeddelka weli lama heli karo'),
  'warning.shell.trendText': message(
    'The dashboard API returns point-in-time totals only — no time-series endpoint exists yet to plot bookings vs. orders over the selected period.',
    'API-ga dashboard-ka wuxuu soo celiyaa wadarooyin hal waqti ah oo keliya — weli ma jiro endpoint taxane-waqti ah oo lagu muujiyo ballamaha iyo dalabyada muddada la doortay.',
  ),
  'warning.shell.activityTitle': message('No activity feed yet', 'Weli ma jiro liiska hawlaha'),
  'warning.shell.activityText': message(
    'No admin-facing activity-feed endpoint exists on the dashboard yet.',
    'Weli dashboard-ka kuma jiro endpoint liiska hawlaha maamulka u muujiya.',
  ),
  'warning.shell.ordersTitle': message('Use the Orders module', 'Isticmaal qaybta Dalabyada'),
  'warning.shell.ordersText': message(
    "A dedicated 'latest orders' widget isn't part of the dashboard API — see the Orders module in Mobile App Management for the full, real order list.",
    "Widget gaar ah oo 'dalabyadii ugu dambeeyay' ma ahan qayb ka mid ah API-ga dashboard-ka — u eeg qaybta Dalabyada ee Maamulka App-ka Mobaylka si aad u aragto liiska dalabyada oo dhammaystiran.",
  ),
  'warning.shell.revenueTitle': message('No revenue breakdown yet', 'Weli ma jiro kala-bixinta dakhliga'),
  'warning.shell.revenueText': message(
    "The dashboard reports one total revenue figure — a services/products/bookings split isn't exposed by the API yet.",
    'Dashboard-ku wuxuu muujiyaa hal wadarta dakhli — kala-bixinta adeegyada/alaabta/ballamaha weli API-gu ma bixiyo.',
  ),

  // The dashboard cards, keyed by the label the server sends
  'status.shell.widget.Bookings': message('Bookings', 'Ballamaha'),
  'status.shell.widget.Quotations': message('Quotations', 'Qiimeynada'),
  'status.shell.widget.Orders': message('Orders', 'Dalabyada'),
  'status.shell.widget.Payments': message('Payments', 'Lacag-bixinnada'),
  'status.shell.widget.Revenue': message('Revenue', 'Dakhliga'),
  'status.shell.widget.Products': message('Products', 'Alaabta'),
  'status.shell.widget.Customers': message('Customers', 'Macaamiisha'),
});
