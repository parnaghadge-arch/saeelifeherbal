<?php
/**
 * Shared enumerations and status constants.
 */
declare(strict_types=1);

// Order lifecycle.
// Deliberately short: an order is new until the admin approves it, then it moves down the
// fulfilment line to delivered. Money is tracked separately on orders.payment_status, so the
// status column never has to double as a payment flag (that is what made the old eight-value
// list confusing). "Approve" = moving an order to processing.
const ORDER_STATUSES = [
    'new'        => 'New',
    'processing' => 'Processing',
    'shipped'    => 'Shipped',
    'delivered'  => 'Delivered',
    'cancelled'  => 'Cancelled',
];
const ORDER_STATUS_DEFAULT = 'new';
// Statuses an admin can pick in the dropdown. Cancel is a separate action, not a dropdown
// choice, so it is not listed here.
const ORDER_STATUS_ACTIONS = ['new', 'processing', 'shipped', 'delivered'];
// Orders written before the list was shortened still carry the old words. They are read
// through this map so history rows and legacy data keep making sense on screen.
const ORDER_STATUS_LEGACY = [
    'pending'         => 'new',
    'payment_pending' => 'new',
    'paid'            => 'processing',
    'refunded'        => 'cancelled',
];
// Commission is generated when an order reaches one of these statuses (admin approval or
// delivery), never on payment alone.
const ORDER_COMMISSION_STATUSES = ['processing', 'shipped', 'delivered'];
const ORDER_PAYMENT_STATUSES = ['unpaid' => 'Unpaid', 'pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded'];

// Commission
const COMMISSION_TYPES = [
    'direct'       => 'Direct Sponsor Commission',
    'grand_direct' => 'Grand Direct Sponsor Commission',
    'royalty'      => 'Royalty Income',
    'incentive'    => 'Other Incentive',
];
const COMMISSION_STATUSES = [
    'pending'   => 'Pending',
    'approved'  => 'Approved',
    'available' => 'Available',
    'paid'      => 'Paid',
    'rejected'  => 'Rejected',
    'reversed'  => 'Reversed',
];
const WALLET_TYPES = [
    'direct'       => 'Direct Sponsor Commission',
    'grand_direct' => 'Grand Direct Sponsor Commission',
    'royalty'      => 'Royalty Income',
    'incentive'    => 'Other Incentives',
    'pending'      => 'Pending Earnings',
    'available'    => 'Available Balance',
    'paid'         => 'Paid Amount',
];

// KYC
const KYC_STATUSES = [
    'not_submitted'         => 'Not Submitted',
    'submitted'             => 'Submitted',
    'under_verification'    => 'Under Verification',
    'approved'              => 'Approved',
    'rejected'              => 'Rejected',
    'resubmission_required' => 'Resubmission Required',
];

// Payouts
// Lifecycle: pending (in report, awaiting download) -> processing (report downloaded,
// payment in progress) -> paid (completed). approved is kept for manual requests that
// an admin approves before payment; auto payouts go pending -> processing -> paid.
const PAYOUT_STATUSES = [
    'pending'   => 'Pending',
    'approved'  => 'Approved',
    'processing'=> 'In Progress',
    'rejected'  => 'Rejected',
    'paid'      => 'Paid',
    'cancelled' => 'Cancelled',
];

// Member / customer status
const MEMBER_STATUSES = ['pending' => 'Pending', 'active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'];
const MEMBER_ROLES = ['farmer' => 'Farmer', 'distributor' => 'Distributor', 'retailer' => 'Retailer', 'customer' => 'Customer'];

// Payments.
// Only Cash on Delivery is live today. UPI and Razorpay are wired end to end but stay switched
// off until the admin turns them on from Admin > Settings > Payment methods.
const PAYMENT_METHODS = ['cod' => 'Cash on Delivery', 'upi' => 'UPI', 'razorpay' => 'Razorpay'];
// Payment mode used only by assisted purchases (admin/sponsor buying for a member) that are
// settled from the member's own wallet balance.
const PAYMENT_METHOD_WALLET = 'wallet';
const PAYMENT_GATEWAYS = ['razorpay', 'cod', 'upi'];
// Method an order falls back to when the requested one is unavailable.
const PAYMENT_METHOD_DEFAULT = 'cod';
// Retired methods. Not offered at checkout any more, but their labels survive so orders placed
// before the change still read correctly.
const PAYMENT_METHODS_LEGACY = ['bank_transfer' => 'Bank Transfer'];

// Enquiry status
const ENQUIRY_STATUSES = ['new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'spam' => 'Spam'];

// Notification channels
const NOTIFICATION_CHANNELS = ['in_system' => 'In-System', 'email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];

// Slider effects
const SLIDER_TRANSITIONS = ['fade' => 'Fade', 'slide' => 'Slide', 'zoom' => 'Zoom In', 'kenburns' => 'Ken Burns', 'parallax' => 'Parallax', 'soft' => 'Soft Transition'];
const TEXT_ANIMATIONS = ['fade-up' => 'Fade Up', 'fade-in' => 'Fade In', 'slide-left' => 'Slide From Left', 'slide-right' => 'Slide From Right', 'zoom-in' => 'Zoom In', 'none' => 'None'];
const IMAGE_ANIMATIONS = ['kenburns' => 'Ken Burns', 'zoom' => 'Slow Zoom', 'pan' => 'Slow Pan', 'none' => 'None'];
