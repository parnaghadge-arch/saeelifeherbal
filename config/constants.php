<?php
/**
 * Shared enumerations and status constants.
 */
declare(strict_types=1);

// Order lifecycle
const ORDER_STATUSES = [
    'pending'         => 'Pending',
    'payment_pending' => 'Payment Pending',
    'paid'            => 'Paid',
    'processing'      => 'Processing',
    'shipped'         => 'Shipped',
    'delivered'       => 'Delivered',
    'cancelled'       => 'Cancelled',
    'refunded'        => 'Refunded',
];
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

// Payments
const PAYMENT_METHODS = ['cod' => 'Cash on Delivery', 'razorpay' => 'Razorpay', 'upi' => 'UPI', 'bank_transfer' => 'Bank Transfer'];
// Payment mode used only by assisted purchases (admin/sponsor buying for a member) that are
// settled from the member's own wallet balance.
const PAYMENT_METHOD_WALLET = 'wallet';
const PAYMENT_GATEWAYS = ['razorpay', 'cod', 'upi', 'bank_transfer'];

// Enquiry status
const ENQUIRY_STATUSES = ['new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'spam' => 'Spam'];

// Notification channels
const NOTIFICATION_CHANNELS = ['in_system' => 'In-System', 'email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];

// Slider effects
const SLIDER_TRANSITIONS = ['fade' => 'Fade', 'slide' => 'Slide', 'zoom' => 'Zoom In', 'kenburns' => 'Ken Burns', 'parallax' => 'Parallax', 'soft' => 'Soft Transition'];
const TEXT_ANIMATIONS = ['fade-up' => 'Fade Up', 'fade-in' => 'Fade In', 'slide-left' => 'Slide From Left', 'slide-right' => 'Slide From Right', 'zoom-in' => 'Zoom In', 'none' => 'None'];
const IMAGE_ANIMATIONS = ['kenburns' => 'Ken Burns', 'zoom' => 'Slow Zoom', 'pan' => 'Slow Pan', 'none' => 'None'];
