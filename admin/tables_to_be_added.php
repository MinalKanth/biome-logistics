CREATE TABLE transport_bookings (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    tracking_id VARCHAR(25) NOT NULL UNIQUE,

    enquiry_reference VARCHAR(25) NULL,

    customer_name VARCHAR(150) NOT NULL,

    company_name VARCHAR(200) NULL,

    email VARCHAR(150) NULL,

    phone VARCHAR(20) NOT NULL,

    alternate_phone VARCHAR(20) NULL,

    service_type VARCHAR(100) NULL,

    truck_type VARCHAR(100) NULL,

    vehicle_type VARCHAR(100) NULL,

    cargo_type VARCHAR(150) NULL,

    cargo_description TEXT NULL,

    cargo_weight DECIMAL(10,2) NULL,

    cargo_unit ENUM(
        'KG',
        'TON',
        'QUINTAL',
        'BOX',
        'BAG',
        'PIECE'
    ) DEFAULT 'KG',

    number_of_packages INT DEFAULT 0,

    pickup_address TEXT,

    pickup_city VARCHAR(100),

    pickup_state VARCHAR(100),

    pickup_pincode VARCHAR(15),

    pickup_contact_person VARCHAR(150),

    pickup_contact_number VARCHAR(20),

    drop_address TEXT,

    drop_city VARCHAR(100),

    drop_state VARCHAR(100),

    drop_pincode VARCHAR(15),

    drop_contact_person VARCHAR(150),

    drop_contact_number VARCHAR(20),

    distance_km DECIMAL(10,2) DEFAULT 0,

    expected_days INT DEFAULT 1,

    status ENUM(

        'pending',

        'reviewed',

        'accepted',

        'quotation_sent',

        'quotation_approved',

        'advance_received',

        'vehicle_assigned',

        'driver_assigned',

        'driver_started',

        'reached_pickup',

        'loading_started',

        'loaded',

        'in_transit',

        'crossed_checkpoint',

        'reached_destination_city',

        'out_for_delivery',

        'reached_destination',

        'unloading',

        'delivered',

        'pod_uploaded',

        'payment_pending',

        'completed',

        'cancelled',

        'hold',

        'failed',

        'returned'

    ) DEFAULT 'pending',

    priority ENUM(

        'Low',

        'Normal',

        'High',

        'Urgent'

    ) DEFAULT 'Normal',

    driver_id BIGINT UNSIGNED NULL,

    vehicle_id BIGINT UNSIGNED NULL,

    total_amount DECIMAL(12,2) DEFAULT 0,

    gst_amount DECIMAL(12,2) DEFAULT 0,

    toll_amount DECIMAL(12,2) DEFAULT 0,

    fuel_charge DECIMAL(12,2) DEFAULT 0,

    labour_charge DECIMAL(12,2) DEFAULT 0,

    extra_charge DECIMAL(12,2) DEFAULT 0,

    discount DECIMAL(12,2) DEFAULT 0,

    grand_total DECIMAL(12,2) DEFAULT 0,

    advance_paid DECIMAL(12,2) DEFAULT 0,

    paid_amount DECIMAL(12,2) DEFAULT 0,

    balance_amount DECIMAL(12,2) DEFAULT 0,

    payment_status ENUM(

        'Unpaid',

        'Advance Paid',

        'Partially Paid',

        'Paid'

    ) DEFAULT 'Unpaid',

    payment_mode VARCHAR(50),

    invoice_number VARCHAR(50),

    lr_number VARCHAR(50),

    quotation_number VARCHAR(50),

    scheduled_pickup DATETIME NULL,

    actual_pickup DATETIME NULL,

    expected_delivery DATETIME NULL,

    delivered_at DATETIME NULL,

    last_location VARCHAR(255),

    gps_location TEXT,

    remarks TEXT,

    internal_notes TEXT,

    customer_notes TEXT,

    created_by BIGINT UNSIGNED NULL,

    updated_by BIGINT UNSIGNED NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,

    INDEX(status),

    INDEX(payment_status),

    INDEX(tracking_id),

    INDEX(phone),

    INDEX(email),

    INDEX(driver_id),

    INDEX(vehicle_id)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO transport_bookings (

tracking_id,

customer_name,

company_name,

email,

phone,

service_type,

truck_type,

cargo_type,

cargo_weight,

pickup_city,

pickup_state,

drop_city,

drop_state,

status,

priority,

grand_total,

advance_paid,

paid_amount,

balance_amount,

payment_status,

scheduled_pickup,

expected_delivery

)

VALUES

(

'BITR-2026-000001',

'Rahul Sharma',

'ABC Traders',

'rahul@gmail.com',

'9876543210',

'Transportation',

'32 Ft Multi Axle',

'Electronics',

12000,

'Guwahati',

'Assam',

'Shillong',

'Meghalaya',

'in_transit',

'High',

85000,

30000,

30000,

55000,

'Advance Paid',

'2026-07-11 09:00:00',

'2026-07-13 17:00:00'

),

(

'BITR-2026-000002',

'Priya Das',

'North East Cement',

'priya@gmail.com',

'9123456789',

'Transportation',

'32 Ft Single Axle',

'Cement',

22000,

'Nagaon',

'Assam',

'Silchar',

'Assam',

'driver_assigned',

'Normal',

45000,

10000,

10000,

35000,

'Advance Paid',

'2026-07-12 08:00:00',

'2026-07-14 19:00:00'

),

(

'BITR-2026-000003',

'Biome Enterprises',

'Biome Enterprises',

'director@biomeenterprises.com',

'9678431656',

'Transportation',

'Open Body',

'Steel',

18500,

'Guwahati',

'Assam',

'Agartala',

'Tripura',

'completed',

'Urgent',

120000,

120000,

120000,

0,

'Paid',

'2026-07-05 06:30:00',

'2026-07-08 14:00:00'

);


CREATE TABLE transport_booking_timeline (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    booking_id BIGINT UNSIGNED NOT NULL,

    tracking_id VARCHAR(25) NOT NULL,

    status ENUM(

        'pending',
        'reviewed',
        'accepted',
        'quotation_sent',
        'quotation_approved',
        'advance_received',
        'vehicle_assigned',
        'driver_assigned',
        'driver_started',
        'reached_pickup',
        'loading_started',
        'loaded',
        'in_transit',
        'checkpoint',
        'traffic_delay',
        'weather_delay',
        'fuel_stop',
        'rest_stop',
        'reached_destination_city',
        'out_for_delivery',
        'reached_destination',
        'unloading',
        'delivered',
        'pod_uploaded',
        'payment_pending',
        'payment_received',
        'completed',
        'cancelled',
        'returned',
        'hold'

    ) NOT NULL,

    title VARCHAR(150) NOT NULL,

    description TEXT,

    current_location VARCHAR(255),

    latitude DECIMAL(10,8) NULL,

    longitude DECIMAL(11,8) NULL,

    estimated_arrival DATETIME NULL,

    delay_minutes INT DEFAULT 0,

    progress_percent TINYINT UNSIGNED DEFAULT 0,

    driver_name VARCHAR(150),

    driver_phone VARCHAR(20),

    vehicle_number VARCHAR(50),

    customer_visible TINYINT(1) DEFAULT 1,

    notify_customer TINYINT(1) DEFAULT 0,

    sms_sent TINYINT(1) DEFAULT 0,

    email_sent TINYINT(1) DEFAULT 0,

    whatsapp_sent TINYINT(1) DEFAULT 0,

    attachment VARCHAR(255),

    icon VARCHAR(50),

    color VARCHAR(20),

    created_by BIGINT UNSIGNED NULL,

    ip_address VARCHAR(45),

    device_info VARCHAR(255),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_transport_timeline_booking
        FOREIGN KEY (booking_id)
        REFERENCES transport_bookings(id)
        ON DELETE CASCADE,

    INDEX(booking_id),

    INDEX(tracking_id),

    INDEX(status),

    INDEX(created_at)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO transport_booking_timeline
(
booking_id,
tracking_id,
status,
title,
description,
current_location,
progress_percent,
customer_visible,
notify_customer,
driver_name,
driver_phone,
vehicle_number,
icon,
color
)

VALUES

(
1,
'BITR-2026-000001',
'accepted',
'Booking Accepted',
'Your transport booking has been approved by our logistics team.',
'Guwahati Office',
10,
1,
1,
NULL,
NULL,
NULL,
'check-circle',
'green'
),

(
1,
'BITR-2026-000001',
'driver_assigned',
'Driver Assigned',
'Driver Rajesh Kumar has been assigned for pickup.',
'Guwahati',
20,
1,
1,
'Rajesh Kumar',
'9876543211',
'AS01AB4587',
'truck',
'blue'
),

(
1,
'BITR-2026-000001',
'reached_pickup',
'Driver Reached Pickup',
'Driver has reached the pickup location and is waiting for loading.',
'Guwahati Warehouse',
35,
1,
1,
'Rajesh Kumar',
'9876543211',
'AS01AB4587',
'map-marker',
'orange'
),

(
1,
'BITR-2026-000001',
'loading_started',
'Loading Started',
'Loading operation has started.',
'Warehouse',
45,
1,
0,
'Rajesh Kumar',
'9876543211',
'AS01AB4587',
'boxes',
'purple'
),

(
1,
'BITR-2026-000001',
'loaded',
'Cargo Loaded',
'All cargo loaded successfully.',
'Warehouse',
55,
1,
1,
'Rajesh Kumar',
'9876543211',
'AS01AB4587',
'dolly',
'green'
),

(
1,
'BITR-2026-000001',
'in_transit',
'Shipment In Transit',
'Vehicle has started towards destination.',
'NH-27 Assam',
70,
1,
1,
'Rajesh Kumar',
'9876543211',
'AS01AB4587',
'road',
'blue'
),

(
1,
'BITR-2026-000001',
'checkpoint',
'Reached Checkpoint',
'Vehicle crossed Nongpoh Checkpost.',
'Nongpoh',
82,
1,
0,
'Rajesh Kumar',
'9876543211',
'AS01AB4587',
'flag',
'cyan'
),

(
1,
'BITR-2026-000001',
'out_for_delivery',
'Out For Delivery',
'Shipment is now heading towards final destination.',
'Shillong',
92,
1,
1,
'Rajesh Kumar',
'9876543211',
'AS01AB4587',
'location-arrow',
'orange'
),

(
1,
'BITR-2026-000001',
'delivered',
'Shipment Delivered',
'Goods successfully delivered.',
'Shillong',
100,
1,
1,
'Rajesh Kumar',
'9876543211',
'AS01AB4587',
'check-double',
'green'
);

CREATE TABLE transport_payment_history (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    booking_id BIGINT UNSIGNED NOT NULL,

    tracking_id VARCHAR(25) NOT NULL,

    receipt_number VARCHAR(30) UNIQUE,

    invoice_number VARCHAR(30),

    payment_type ENUM(
        'advance',
        'partial',
        'final',
        'refund',
        'adjustment'
    ) NOT NULL,

    payment_mode ENUM(
        'cash',
        'upi',
        'bank_transfer',
        'cheque',
        'card',
        'wallet'
    ) NOT NULL,

    amount DECIMAL(12,2) NOT NULL,

    gst_amount DECIMAL(12,2) DEFAULT 0,

    tds_amount DECIMAL(12,2) DEFAULT 0,

    processing_fee DECIMAL(12,2) DEFAULT 0,

    transaction_id VARCHAR(150),

    utr_number VARCHAR(150),

    cheque_number VARCHAR(100),

    bank_name VARCHAR(150),

    payment_date DATETIME NOT NULL,

    verified ENUM(
        'pending',
        'verified',
        'rejected'
    ) DEFAULT 'pending',

    verified_by BIGINT UNSIGNED NULL,

    verified_at DATETIME NULL,

    remarks TEXT,

    attachment VARCHAR(255),

    created_by BIGINT UNSIGNED,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_transport_payment_booking
        FOREIGN KEY (booking_id)
        REFERENCES transport_bookings(id)
        ON DELETE CASCADE,

    INDEX(booking_id),

    INDEX(tracking_id),

    INDEX(payment_type),

    INDEX(payment_mode),

    INDEX(payment_date)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO transport_payment_history
(
booking_id,
tracking_id,
receipt_number,
invoice_number,
payment_type,
payment_mode,
amount,
gst_amount,
transaction_id,
utr_number,
payment_date,
verified,
remarks
)

VALUES

(
1,
'BITR-2026-000001',
'RCPT-000001',
'INV-2026-000001',
'advance',
'upi',
30000,
5400,
'UPI837483748',
'UTR234234234',
'2026-07-10 09:35:00',
'verified',
'Advance payment received.'
),

(
1,
'BITR-2026-000001',
'RCPT-000002',
'INV-2026-000001',
'partial',
'bank_transfer',
25000,
4500,
'BANK8349834',
'UTR9988776655',
'2026-07-12 11:15:00',
'verified',
'Second installment received.'
),

(
1,
'BITR-2026-000001',
'RCPT-000003',
'INV-2026-000001',
'final',
'bank_transfer',
30000,
5400,
'BANK111222333',
'UTR555666777',
'2026-07-14 05:10:00',
'verified',
'Final payment received.'
);




CREATE TABLE transport_drivers (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    driver_code VARCHAR(20) NOT NULL UNIQUE,

    full_name VARCHAR(150) NOT NULL,

    photo VARCHAR(255) NULL,

    mobile VARCHAR(20) NOT NULL,

    alternate_mobile VARCHAR(20) NULL,

    email VARCHAR(150) NULL,

    date_of_birth DATE NULL,

    gender ENUM(
        'Male',
        'Female',
        'Other'
    ) DEFAULT 'Male',

    blood_group VARCHAR(10) NULL,

    aadhaar_number VARCHAR(20) NULL,

    pan_number VARCHAR(20) NULL,

    driving_license_number VARCHAR(100) NOT NULL,

    license_category VARCHAR(100) NULL,

    license_issue_date DATE NULL,

    license_expiry_date DATE NULL,

    experience_years DECIMAL(4,1) DEFAULT 0,

    joining_date DATE NULL,

    salary DECIMAL(12,2) DEFAULT 0,

    address TEXT NULL,

    city VARCHAR(100) NULL,

    state VARCHAR(100) NULL,

    pincode VARCHAR(15) NULL,

    emergency_contact_name VARCHAR(150) NULL,

    emergency_contact_number VARCHAR(20) NULL,

    emergency_relationship VARCHAR(100) NULL,

    current_vehicle_id BIGINT UNSIGNED NULL,

    current_booking_id BIGINT UNSIGNED NULL,

    availability ENUM(

        'Available',

        'On Trip',

        'On Leave',

        'Rest',

        'Maintenance',

        'Inactive'

    ) DEFAULT 'Available',

    employment_status ENUM(

        'Active',

        'Suspended',

        'Retired',

        'Resigned'

    ) DEFAULT 'Active',

    rating DECIMAL(3,2) DEFAULT 5.00,

    total_trips INT DEFAULT 0,

    completed_trips INT DEFAULT 0,

    cancelled_trips INT DEFAULT 0,

    total_distance DECIMAL(12,2) DEFAULT 0,

    total_deliveries INT DEFAULT 0,

    accident_count INT DEFAULT 0,

    penalty_points INT DEFAULT 0,

    gps_device_id VARCHAR(100) NULL,

    live_location TEXT NULL,

    last_location_update DATETIME NULL,

    notes TEXT NULL,

    created_by BIGINT UNSIGNED NULL,

    updated_by BIGINT UNSIGNED NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX(driver_code),

    INDEX(mobile),

    INDEX(availability),

    INDEX(employment_status),

    INDEX(current_vehicle_id),

    INDEX(current_booking_id)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO transport_drivers
(

driver_code,

full_name,

mobile,

email,

blood_group,

driving_license_number,

license_category,

license_issue_date,

license_expiry_date,

experience_years,

joining_date,

salary,

city,

state,

availability,

employment_status,

rating,

total_trips,

completed_trips,

total_distance

)

VALUES

(

'DRV000001',

'Rajesh Kumar',

'9876543211',

'rajesh@biomeenterprises.com',

'O+',

'DL-AS-987654321',

'Heavy Motor Vehicle',

'2021-01-15',

'2031-01-15',

8,

'2022-05-01',

35000,

'Guwahati',

'Assam',

'On Trip',

'Active',

4.9,

620,

615,

185420

),

(

'DRV000002',

'Amit Das',

'9876543222',

'amit@biomeenterprises.com',

'B+',

'DL-AS-876543210',

'Heavy Motor Vehicle',

'2020-03-10',

'2030-03-10',

12,

'2021-02-10',

42000,

'Nagaon',

'Assam',

'Available',

'Active',

4.8,

810,

804,

268510

),

(

'DRV000003',

'Bikash Sharma',

'9876543333',

'bikash@biomeenterprises.com',

'A+',

'DL-AS-564738291',

'Heavy Motor Vehicle',

'2019-07-18',

'2029-07-18',

15,

'2020-01-20',

48000,

'Shillong',

'Meghalaya',

'Available',

'Active',

5.0,

1052,

1048,

402650

);


CREATE TABLE transport_vehicles (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    vehicle_code VARCHAR(20) NOT NULL UNIQUE,

    registration_number VARCHAR(30) NOT NULL UNIQUE,

    vehicle_type ENUM(
        '32 Ft Single Axle',
        '32 Ft Multi Axle',
        '32 Ft Open Body',
        'Trailer',
        'Pickup',
        'Mini Truck',
        'Other'
    ) NOT NULL,

    brand VARCHAR(100),

    model VARCHAR(100),

    manufacture_year YEAR,

    engine_number VARCHAR(100),

    chassis_number VARCHAR(100),

    color VARCHAR(50),

    load_capacity DECIMAL(10,2),

    load_unit ENUM(
        'KG',
        'TON'
    ) DEFAULT 'TON',

    fuel_type ENUM(
        'Diesel',
        'Petrol',
        'CNG',
        'Electric'
    ) DEFAULT 'Diesel',

    average_mileage DECIMAL(5,2),

    odometer BIGINT DEFAULT 0,

    rc_number VARCHAR(100),

    rc_expiry DATE,

    insurance_company VARCHAR(150),

    insurance_policy_number VARCHAR(150),

    insurance_expiry DATE,

    permit_number VARCHAR(150),

    permit_expiry DATE,

    fitness_certificate_number VARCHAR(150),

    fitness_expiry DATE,

    pollution_certificate_number VARCHAR(150),

    pollution_expiry DATE,

    fastag_number VARCHAR(100),

    gps_device_id VARCHAR(100),

    gps_enabled TINYINT(1) DEFAULT 0,

    current_driver_id BIGINT UNSIGNED,

    current_booking_id BIGINT UNSIGNED,

    status ENUM(

        'Available',

        'Assigned',

        'In Transit',

        'Maintenance',

        'Breakdown',

        'Inactive'

    ) DEFAULT 'Available',

    purchase_date DATE,

    purchase_price DECIMAL(12,2),

    total_trips INT DEFAULT 0,

    completed_trips INT DEFAULT 0,

    total_distance DECIMAL(12,2) DEFAULT 0,

    last_service_date DATE,

    next_service_due DATE,

    notes TEXT,

    created_by BIGINT UNSIGNED,

    updated_by BIGINT UNSIGNED,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,

    INDEX(vehicle_code),

    INDEX(registration_number),

    INDEX(status),

    INDEX(current_driver_id),

    INDEX(current_booking_id)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


INSERT INTO transport_vehicles
(

vehicle_code,

registration_number,

vehicle_type,

brand,

model,

manufacture_year,

load_capacity,

fuel_type,

average_mileage,

odometer,

insurance_company,

insurance_policy_number,

insurance_expiry,

permit_number,

permit_expiry,

fitness_certificate_number,

fitness_expiry,

pollution_certificate_number,

pollution_expiry,

fastag_number,

gps_enabled,

status,

purchase_date,

purchase_price,

total_trips,

completed_trips,

total_distance,

last_service_date,

next_service_due

)

VALUES

(

'VEH000001',

'AS01AB4587',

'32 Ft Multi Axle',

'Tata',

'Signa 5530.S',

2023,

25,

'Diesel',

4.8,

185420,

'ICICI Lombard',

'ICI23456789',

'2027-05-10',

'NP20260001',

'2027-04-15',

'FIT20260001',

'2027-01-10',

'PUC20260001',

'2026-10-15',

'FT123456789',

1,

'In Transit',

'2023-02-01',

4850000,

620,

615,

185420,

'2026-06-15',

'2026-09-15'

),

(

'VEH000002',

'AS02CD1122',

'32 Ft Single Axle',

'Ashok Leyland',

'AVTR 4220',

2022,

18,

'Diesel',

5.4,

132500,

'HDFC ERGO',

'HDFC778899',

'2027-02-12',

'NP20260002',

'2027-03-20',

'FIT20260002',

'2027-02-15',

'PUC20260002',

'2026-09-01',

'FT987654321',

1,

'Available',

'2022-08-01',

3650000,

420,

418,

132500,

'2026-05-20',

'2026-08-20'

),

(

'VEH000003',

'ML05XY7890',

'32 Ft Open Body',

'BharatBenz',

'3528R',

2024,

22,

'Diesel',

5.1,

68500,

'New India Assurance',

'NIA456789',

'2028-01-01',

'NP20260003',

'2027-12-31',

'FIT20260003',

'2027-12-15',

'PUC20260003',

'2026-12-01',

'FT456123789',

1,

'Available',

'2024-03-15',

4250000,

165,

162,

68500,

'2026-07-01',

'2026-10-01'

);



CREATE TABLE transport_documents (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    booking_id BIGINT UNSIGNED NULL,

    tracking_id VARCHAR(25) NULL,

    driver_id BIGINT UNSIGNED NULL,

    vehicle_id BIGINT UNSIGNED NULL,

    document_type ENUM(

        'Quotation',

        'Invoice',

        'GST Invoice',

        'LR',

        'E-Way Bill',

        'Delivery Challan',

        'POD',

        'Payment Receipt',

        'Cargo Photo',

        'Loading Photo',

        'Unloading Photo',

        'Vehicle Photo',

        'Driver License',

        'Insurance',

        'Fitness',

        'RC',

        'Permit',

        'Weight Slip',

        'Gate Pass',

        'Customer Document',

        'Other'

    ) NOT NULL,

    document_title VARCHAR(200) NOT NULL,

    file_name VARCHAR(255) NOT NULL,

    original_file_name VARCHAR(255),

    file_extension VARCHAR(20),

    mime_type VARCHAR(100),

    file_size BIGINT DEFAULT 0,

    file_path VARCHAR(500) NOT NULL,

    version INT DEFAULT 1,

    expiry_date DATE NULL,

    verification_status ENUM(

        'Pending',

        'Verified',

        'Rejected'

    ) DEFAULT 'Pending',

    verified_by BIGINT UNSIGNED NULL,

    verified_at DATETIME NULL,

    remarks TEXT,

    uploaded_by BIGINT UNSIGNED,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_transport_document_booking
        FOREIGN KEY (booking_id)
        REFERENCES transport_bookings(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_transport_document_driver
        FOREIGN KEY (driver_id)
        REFERENCES transport_drivers(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_transport_document_vehicle
        FOREIGN KEY (vehicle_id)
        REFERENCES transport_vehicles(id)
        ON DELETE SET NULL,

    INDEX(booking_id),

    INDEX(driver_id),

    INDEX(vehicle_id),

    INDEX(document_type),

    INDEX(tracking_id)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO transport_documents
(

booking_id,

tracking_id,

document_type,

document_title,

file_name,

original_file_name,

file_extension,

mime_type,

file_size,

file_path,

verification_status,

remarks

)

VALUES

(

1,

'BITR-2026-000001',

'Invoice',

'Freight Invoice',

'INV20260001.pdf',

'Invoice.pdf',

'pdf',

'application/pdf',

285640,

'uploads/transport/invoices/INV20260001.pdf',

'Verified',

'Final invoice generated.'

),

(

1,

'BITR-2026-000001',

'LR',

'Lorry Receipt',

'LR20260001.pdf',

'LR.pdf',

'pdf',

'application/pdf',

180524,

'uploads/transport/lr/LR20260001.pdf',

'Verified',

'Original LR'

),

(

1,

'BITR-2026-000001',

'Cargo Photo',

'Cargo Before Loading',

'cargo1.jpg',

'cargo.jpg',

'jpg',

'image/jpeg',

425154,

'uploads/transport/photos/cargo1.jpg',

'Verified',

'Captured before loading'

),

(

1,

'BITR-2026-000001',

'Loading Photo',

'Loading Process',

'loading.jpg',

'loading.jpg',

'jpg',

'image/jpeg',

514212,

'uploads/transport/photos/loading.jpg',

'Verified',

'Loading completed'

),

(

1,

'BITR-2026-000001',

'POD',

'Proof Of Delivery',

'pod.pdf',

'POD.pdf',

'pdf',

'application/pdf',

221478,

'uploads/transport/pod/pod.pdf',

'Verified',

'Signed by customer'

);

CREATE TABLE transport_booking_notes (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    booking_id BIGINT UNSIGNED NOT NULL,

    tracking_id VARCHAR(25) NOT NULL,

    note_type ENUM(
        'General',
        'Customer',
        'Payment',
        'Dispatch',
        'Driver',
        'Vehicle',
        'Complaint',
        'Follow Up',
        'Reminder',
        'Important'
    ) DEFAULT 'General',

    priority ENUM(
        'Low',
        'Normal',
        'High',
        'Urgent'
    ) DEFAULT 'Normal',

    title VARCHAR(200) NOT NULL,

    note TEXT NOT NULL,

    is_pinned TINYINT(1) DEFAULT 0,

    customer_visible TINYINT(1) DEFAULT 0,

    reminder_at DATETIME NULL,

    attachment VARCHAR(255),

    mentioned_admin_id BIGINT UNSIGNED NULL,

    created_by BIGINT UNSIGNED NOT NULL,

    updated_by BIGINT UNSIGNED NULL,

    edited_at DATETIME NULL,

    is_deleted TINYINT(1) DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_booking_notes
        FOREIGN KEY (booking_id)
        REFERENCES transport_bookings(id)
        ON DELETE CASCADE,

    INDEX(booking_id),

    INDEX(tracking_id),

    INDEX(priority),

    INDEX(note_type),

    INDEX(created_by),

    INDEX(reminder_at)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


INSERT INTO transport_booking_notes
(

booking_id,

tracking_id,

note_type,

priority,

title,

note,

is_pinned,

customer_visible,

created_by

)

VALUES

(

1,

'BITR-2026-000001',

'Dispatch',

'High',

'Vehicle Assigned',

'Vehicle AS01AB4587 assigned successfully.',

1,

0,

1

),

(

1,

'BITR-2026-000001',

'Customer',

'Normal',

'Customer Call',

'Customer requested delivery before 4 PM if possible.',

0,

0,

1

),

(

1,

'BITR-2026-000001',

'Payment',

'High',

'Advance Received',

'Advance payment of ₹30,000 received through UPI.',

1,

0,

1

),

(

1,

'BITR-2026-000001',

'Reminder',

'Urgent',

'Collect POD',

'Collect signed POD immediately after unloading.',

1,

0,

1

);



CREATE TABLE transport_notifications (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    booking_id BIGINT UNSIGNED NULL,

    tracking_id VARCHAR(25) NULL,

    customer_name VARCHAR(150),

    customer_email VARCHAR(150),

    customer_mobile VARCHAR(20),

    notification_type ENUM(

        'Email',

        'SMS',

        'WhatsApp',

        'Push',

        'System'

    ) NOT NULL,

    event_type ENUM(

        'Booking Created',

        'Booking Accepted',

        'Quotation Sent',

        'Advance Received',

        'Driver Assigned',

        'Driver Started',

        'Reached Pickup',

        'Loading Started',

        'Loaded',

        'In Transit',

        'Reached Destination',

        'Delivered',

        'POD Uploaded',

        'Payment Received',

        'Payment Reminder',

        'Invoice Generated',

        'Reminder',

        'Other'

    ) NOT NULL,

    subject VARCHAR(255),

    message TEXT,

    status ENUM(

        'Pending',

        'Queued',

        'Sending',

        'Sent',

        'Delivered',

        'Failed',

        'Cancelled'

    ) DEFAULT 'Pending',

    priority ENUM(

        'Low',

        'Normal',

        'High',

        'Critical'

    ) DEFAULT 'Normal',

    provider VARCHAR(100),

    provider_reference VARCHAR(255),

    failure_reason TEXT,

    retry_count INT DEFAULT 0,

    scheduled_at DATETIME NULL,

    sent_at DATETIME NULL,

    delivered_at DATETIME NULL,

    read_at DATETIME NULL,

    created_by BIGINT UNSIGNED NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_transport_notification_booking
        FOREIGN KEY (booking_id)
        REFERENCES transport_bookings(id)
        ON DELETE CASCADE,

    INDEX(booking_id),

    INDEX(notification_type),

    INDEX(status),

    INDEX(event_type),

    INDEX(scheduled_at)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


INSERT INTO transport_notifications
(

booking_id,

tracking_id,

customer_name,

customer_email,

customer_mobile,

notification_type,

event_type,

subject,

message,

status,

priority,

provider,

sent_at,

delivered_at

)

VALUES

(

1,

'BITR-2026-000001',

'Rahul Sharma',

'rahul@gmail.com',

'9876543210',

'Email',

'Booking Accepted',

'Your Booking Has Been Accepted',

'Your booking BITR-2026-000001 has been accepted by our logistics team.',

'Delivered',

'Normal',

'SMTP',

NOW(),

NOW()

),

(

1,

'BITR-2026-000001',

'Rahul Sharma',

'rahul@gmail.com',

'9876543210',

'WhatsApp',

'Driver Assigned',

'Driver Assigned',

'Rajesh Kumar has been assigned to your shipment.',

'Delivered',

'High',

'Meta WhatsApp API',

NOW(),

NOW()

),

(

1,

'BITR-2026-000001',

'Rahul Sharma',

'rahul@gmail.com',

'9876543210',

'SMS',

'Delivered',

'Shipment Delivered',

'Your shipment has been delivered successfully.',

'Delivered',

'Critical',

'TextLocal',

NOW(),

NOW()

);



CREATE TABLE transport_activity_logs (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    booking_id BIGINT UNSIGNED NULL,

    tracking_id VARCHAR(25) NULL,

    admin_id BIGINT UNSIGNED NULL,

    admin_name VARCHAR(150) NULL,

    module VARCHAR(100) NOT NULL,

    action VARCHAR(100) NOT NULL,

    entity_type VARCHAR(100) NOT NULL,

    entity_id BIGINT UNSIGNED NULL,

    description TEXT,

    old_values JSON NULL,

    new_values JSON NULL,

    ip_address VARCHAR(45),

    user_agent TEXT,

    browser VARCHAR(100),

    operating_system VARCHAR(100),

    device_type ENUM(
        'Desktop',
        'Mobile',
        'Tablet',
        'API'
    ) DEFAULT 'Desktop',

    request_method VARCHAR(10),

    request_url VARCHAR(255),

    response_code SMALLINT DEFAULT 200,

    execution_time DECIMAL(10,4),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_booking (booking_id),
    INDEX idx_tracking (tracking_id),
    INDEX idx_admin (admin_id),
    INDEX idx_module (module),
    INDEX idx_action (action),
    INDEX idx_created (created_at),

    CONSTRAINT fk_transport_activity_booking
        FOREIGN KEY (booking_id)
        REFERENCES transport_bookings(id)
        ON DELETE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


INSERT INTO transport_activity_logs
(

booking_id,

tracking_id,

admin_id,

admin_name,

module,

action,

entity_type,

entity_id,

description,

old_values,

new_values,

ip_address,

browser,

operating_system,

device_type,

request_method,

request_url

)

VALUES

(

1,

'BITR-2026-000001',

1,

'Mrinal Kanth',

'Bookings',

'Create Booking',

'transport_bookings',

1,

'Transport booking created successfully.',

NULL,

JSON_OBJECT(

'status','pending',

'tracking_id','BITR-2026-000001'

),

'103.52.25.18',

'Chrome',

'Windows 11',

'Desktop',

'POST',

'/admin/transport_add.php'

),

(

1,

'BITR-2026-000001',

1,

'Mrinal Kanth',

'Bookings',

'Status Updated',

'transport_bookings',

1,

'Booking moved to In Transit.',

JSON_OBJECT(

'status','loaded'

),

JSON_OBJECT(

'status','in_transit'

),

'103.52.25.18',

'Chrome',

'Windows 11',

'Desktop',

'POST',

'/admin/update_status.php'

),

(

1,

'BITR-2026-000001',

1,

'Mrinal Kanth',

'Payments',

'Advance Payment',

'transport_payment_history',

1,

'Advance payment verified.',

NULL,

JSON_OBJECT(

'amount',30000,

'status','verified'

),

'103.52.25.18',

'Chrome',

'Windows 11',

'Desktop',

'POST',

'/admin/payment_verify.php'

);


CREATE TABLE transport_sequences (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    sequence_name VARCHAR(100) NOT NULL UNIQUE,

    prefix VARCHAR(20) NOT NULL,

    current_year YEAR NULL,

    current_number BIGINT UNSIGNED NOT NULL DEFAULT 0,

    padding SMALLINT UNSIGNED NOT NULL DEFAULT 6,

    separator_char VARCHAR(5) DEFAULT '-',

    reset_every_year TINYINT(1) NOT NULL DEFAULT 1,

    is_active TINYINT(1) NOT NULL DEFAULT 1,

    description VARCHAR(255),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO transport_sequences
(
sequence_name,
prefix,
current_year,
current_number,
padding,
description
)

VALUES

(
'booking',
'BITR',
2026,
3,
6,
'Transport Booking Number'
),

(
'invoice',
'INV',
2026,
1,
6,
'Invoice Number'
),

(
'quotation',
'QT',
2026,
1,
6,
'Quotation Number'
),

(
'lr',
'LR',
2026,
1,
6,
'Lorry Receipt Number'
),

(
'receipt',
'RCPT',
2026,
3,
6,
'Receipt Number'
),

(
'driver',
'DRV',
NULL,
3,
6,
'Driver Code'
),

(
'vehicle',
'VEH',
NULL,
3,
6,
'Vehicle Code'
);