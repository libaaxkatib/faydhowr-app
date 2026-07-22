import '../domain/entities/order_entities.dart';

/// Mock data for the Orders Module (V1). No backend, no API — matches the
/// Home/Services/Booking/Store Modules' established pattern for content
/// with no live repository yet, even though this module's [OrdersRepository]
/// interface is written for an eventual backend swap.
final List<OrderDetail> mockOrderDetails = <OrderDetail>[
  OrderDetail(
    preview: OrderPreview(
      id: 'sto-101',
      orderNumber: 'STO-2026-000101',
      placedAt: DateTime(2026, 7, 20, 9, 15),
      status: OrderStatus.pending,
      totalAmount: 24.50,
      itemCount: 2,
      firstItemName: 'Multi-Surface Cleaner',
    ),
    items: const <OrderLineItem>[
      OrderLineItem(productName: 'Multi-Surface Cleaner', quantity: 2, unitPrice: 8.50, unit: 'Bottle'),
      OrderLineItem(productName: 'Microfiber Cloth Pack', quantity: 1, unitPrice: 7.00, unit: 'Pack'),
    ],
    deliveryAddress: 'Hodan District, Wadada Sarkanka, Mogadishu',
    subtotal: 24.00,
    deliveryFee: 3.00,
    total: 27.00,
    paymentMethod: OrderPaymentMethod.evcPlus,
  ),
  OrderDetail(
    preview: OrderPreview(
      id: 'sto-102',
      orderNumber: 'STO-2026-000102',
      placedAt: DateTime(2026, 7, 19, 14, 40),
      status: OrderStatus.confirmed,
      totalAmount: 18.00,
      itemCount: 1,
      firstItemName: 'Microfiber Mop Set',
    ),
    items: const <OrderLineItem>[
      OrderLineItem(productName: 'Microfiber Mop Set', quantity: 1, unitPrice: 18.00, unit: 'Piece'),
    ],
    deliveryAddress: 'Bondhere District, KM4, Mogadishu',
    subtotal: 18.00,
    deliveryFee: 3.00,
    total: 21.00,
    paymentMethod: OrderPaymentMethod.eDahab,
    notes: 'Please call on arrival — gate code needed.',
  ),
  OrderDetail(
    preview: OrderPreview(
      id: 'sto-103',
      orderNumber: 'STO-2026-000103',
      placedAt: DateTime(2026, 7, 17, 11, 5),
      status: OrderStatus.preparing,
      totalAmount: 15.50,
      itemCount: 2,
      firstItemName: 'Disposable Gloves Box',
    ),
    items: const <OrderLineItem>[
      OrderLineItem(productName: 'Disposable Gloves Box', quantity: 1, unitPrice: 5.50, unit: 'Box'),
      OrderLineItem(productName: 'Lavender Air Freshener', quantity: 2, unitPrice: 4.50, unit: 'Bottle'),
    ],
    deliveryAddress: 'Hamar Weyne, Old Town, Mogadishu',
    subtotal: 14.50,
    deliveryFee: 3.00,
    discount: 2.00,
    total: 15.50,
    paymentMethod: OrderPaymentMethod.bankTransfer,
  ),
  OrderDetail(
    preview: OrderPreview(
      id: 'sto-104',
      orderNumber: 'STO-2026-000104',
      placedAt: DateTime(2026, 7, 15, 16, 20),
      status: OrderStatus.outForDelivery,
      totalAmount: 12.00,
      itemCount: 1,
      firstItemName: 'Floor Disinfectant',
    ),
    items: const <OrderLineItem>[
      OrderLineItem(productName: 'Floor Disinfectant', quantity: 1, unitPrice: 12.00, unit: 'Bottle'),
    ],
    deliveryAddress: 'Waberi District, Airport Road, Mogadishu',
    subtotal: 12.00,
    deliveryFee: 3.00,
    total: 15.00,
    paymentMethod: OrderPaymentMethod.cashOnDelivery,
  ),
  OrderDetail(
    preview: OrderPreview(
      id: 'sto-105',
      orderNumber: 'STO-2026-000105',
      placedAt: DateTime(2026, 7, 10, 10, 0),
      status: OrderStatus.delivered,
      totalAmount: 36.00,
      itemCount: 3,
      firstItemName: 'Multi-Surface Cleaner',
    ),
    items: const <OrderLineItem>[
      OrderLineItem(productName: 'Multi-Surface Cleaner', quantity: 3, unitPrice: 8.50, unit: 'Bottle'),
      OrderLineItem(productName: 'Glass Cleaner Spray', quantity: 1, unitPrice: 6.00, unit: 'Bottle'),
      OrderLineItem(productName: 'Scrub Brush Kit', quantity: 1, unitPrice: 9.50, unit: 'Pack'),
    ],
    deliveryAddress: 'Hodan District, Wadada Sarkanka, Mogadishu',
    subtotal: 40.00,
    deliveryFee: 3.00,
    discount: 10.50,
    total: 32.50,
    paymentMethod: OrderPaymentMethod.evcPlus,
  ),
  OrderDetail(
    preview: OrderPreview(
      id: 'sto-106',
      orderNumber: 'STO-2026-000106',
      placedAt: DateTime(2026, 7, 5, 8, 30),
      status: OrderStatus.delivered,
      totalAmount: 9.50,
      itemCount: 1,
      firstItemName: 'Scrub Brush Kit',
    ),
    items: const <OrderLineItem>[
      OrderLineItem(productName: 'Scrub Brush Kit', quantity: 1, unitPrice: 9.50, unit: 'Pack'),
    ],
    deliveryAddress: 'Bondhere District, KM4, Mogadishu',
    subtotal: 9.50,
    deliveryFee: 3.00,
    total: 12.50,
    paymentMethod: OrderPaymentMethod.cashOnDelivery,
  ),
  OrderDetail(
    preview: OrderPreview(
      id: 'sto-107',
      orderNumber: 'STO-2026-000107',
      placedAt: DateTime(2026, 7, 2, 13, 45),
      status: OrderStatus.cancelled,
      totalAmount: 22.00,
      itemCount: 1,
      firstItemName: 'Cleaning Caddy Organizer',
    ),
    items: const <OrderLineItem>[
      OrderLineItem(productName: 'Cleaning Caddy Organizer', quantity: 1, unitPrice: 22.00, unit: 'Piece'),
    ],
    deliveryAddress: 'Hamar Weyne, Old Town, Mogadishu',
    subtotal: 22.00,
    deliveryFee: 3.00,
    total: 25.00,
    paymentMethod: OrderPaymentMethod.eDahab,
  ),
];
