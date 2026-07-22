import '../domain/entities/checkout_entities.dart';

/// Mock data for the Checkout Module (V1). No backend, no API — matches
/// the Store/Orders Modules' established pattern.

const List<CheckoutLineItem> mockCheckoutItems = <CheckoutLineItem>[
  CheckoutLineItem(productName: 'Multi-Surface Cleaner', quantity: 2, unitPrice: 8.50, unit: 'Bottle'),
  CheckoutLineItem(productName: 'Microfiber Cloth Pack', quantity: 1, unitPrice: 7.00, unit: 'Pack'),
  CheckoutLineItem(productName: 'Disposable Gloves Box', quantity: 1, unitPrice: 5.50, unit: 'Box'),
];

const List<DeliveryAddressOption> mockSavedAddresses = <DeliveryAddressOption>[
  DeliveryAddressOption(
    id: 'addr-1',
    title: 'Home',
    subtitle: 'Hodan District, Wadada Sarkanka, Mogadishu',
  ),
  DeliveryAddressOption(
    id: 'addr-2',
    title: 'Office',
    subtitle: 'Bondhere District, KM4, Mogadishu',
  ),
  DeliveryAddressOption(
    id: 'addr-3',
    title: 'Other',
    subtitle: 'Hamar Weyne, Old Town, Mogadishu',
  ),
];
