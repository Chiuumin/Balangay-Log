-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 18, 2026 at 05:55 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `balangaylog_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `advisories`
--

CREATE TABLE `advisories` (
  `id` int(11) NOT NULL,
  `title` varchar(120) NOT NULL,
  `body` text NOT NULL,
  `purok_id` int(11) DEFAULT NULL,
  `issued_by_user_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `target_user_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `actor_user_id`, `target_user_id`, `action`, `details`, `created_at`) VALUES
(1, 6, 6, 'LOGOUT', '{\"logout\":true}', '2026-10-07 03:07:15'),
(2, NULL, 7, 'RESIDENT_REGISTRATION', '{\"first_name\":\"Erycka\",\"last_name\":\"Ferranco\",\"username\":\"resident2\",\"contact_number\":\"09106372068\"}', '2026-10-07 03:11:41'),
(3, 6, 6, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-07 03:13:45'),
(4, 6, 6, 'LOGOUT', '{\"logout\":true}', '2026-10-07 03:33:18'),
(5, NULL, 8, 'RESIDENT_REGISTRATION', '{\"first_name\":\"Erycka\",\"last_name\":\"Ferranco\",\"username\":\"resident3\",\"contact_number\":\"09106372068\"}', '2026-10-07 03:34:15'),
(6, 6, 6, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-07 03:34:54'),
(7, 6, 8, 'RESIDENT_DOCUMENT_VIEWED', '{\"resident_name\":\"Erycka Ferranco\"}', '2026-10-07 03:35:18'),
(8, 6, 8, 'RESIDENT_APPROVED', '{\"message\":\"Approved resident account\"}', '2026-10-07 03:35:29'),
(9, 6, 6, 'LOGOUT', '{\"logout\":true}', '2026-10-07 03:35:32'),
(10, 8, 8, 'LOGIN', '{\"username\":\"resident3\",\"role\":\"RESIDENT\"}', '2026-10-07 03:35:41'),
(11, 8, 8, 'LOGOUT', '{\"logout\":true}', '2026-10-07 03:35:47'),
(12, 6, 6, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-07 03:44:51'),
(13, 6, 6, 'PROFILE_UPDATED', '{\"full_name\":\"Maria Santos\",\"contact_number\":null}', '2026-10-07 03:59:24'),
(14, 6, 6, 'LOGOUT', '{\"logout\":true}', '2026-10-07 05:11:07'),
(15, 8, 8, 'FAILED_LOGIN', '{\"username\":\"resident3\"}', '2026-10-07 05:11:16'),
(16, 8, 8, 'LOGIN', '{\"username\":\"resident3\",\"role\":\"RESIDENT\"}', '2026-10-07 05:11:19'),
(17, 8, 8, 'LOGOUT', '{\"logout\":true}', '2026-10-07 05:11:26'),
(18, 8, 8, 'LOGIN', '{\"username\":\"resident3\",\"role\":\"RESIDENT\"}', '2026-10-07 05:13:26'),
(19, 8, 8, 'LOGOUT', '{\"logout\":true}', '2026-10-07 05:14:20'),
(20, 8, 8, 'LOGIN', '{\"username\":\"resident3\",\"role\":\"RESIDENT\"}', '2026-10-07 05:14:43'),
(21, 8, 8, 'LOGOUT', '{\"logout\":true}', '2026-10-07 05:20:13'),
(22, 6, 6, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-07 05:22:43'),
(23, 6, 6, 'LOGOUT', '{\"logout\":true}', '2026-10-07 05:25:20'),
(24, 8, 8, 'FAILED_LOGIN', '{\"username\":\"resident3\"}', '2026-10-07 05:25:30'),
(25, 8, 8, 'LOGIN', '{\"username\":\"resident3\",\"role\":\"RESIDENT\"}', '2026-10-07 05:25:33'),
(26, 8, 8, 'LOGOUT', '{\"logout\":true}', '2026-10-07 05:31:06'),
(27, 6, 6, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-07 05:31:38'),
(28, 6, 6, 'LOGOUT', '{\"logout\":true}', '2026-10-07 05:41:33'),
(29, 6, 6, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-07 05:41:44'),
(30, 6, 6, 'LOGOUT', '{\"logout\":true}', '2026-10-07 05:41:49'),
(31, 8, 8, 'LOGIN', '{\"username\":\"resident3\",\"role\":\"RESIDENT\"}', '2026-10-07 05:41:57'),
(32, 8, 8, 'LOGOUT', '{\"logout\":true}', '2026-10-07 05:42:10'),
(33, 6, 6, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-07 05:44:30'),
(34, 6, 6, 'PROFILE_UPDATED', '{\"full_name\":\"Maria Santos\",\"contact_number\":null}', '2026-10-07 06:23:43'),
(35, 6, 6, 'PROFILE_UPDATED', '{\"full_name\":\"Maria Santos\",\"contact_number\":\"09925798321\"}', '2026-10-07 06:24:16'),
(36, 6, 6, 'PROFILE_UPDATED', '{\"full_name\":\"Maria Santos\",\"contact_number\":\"09925798321\"}', '2026-10-07 06:45:09'),
(37, 6, 6, 'PROFILE_UPDATED', '{\"full_name\":\"Maria Santos\",\"contact_number\":\"09925798321\"}', '2026-10-07 06:57:51'),
(38, 6, 6, 'PROFILE_UPDATED', '{\"full_name\":\"Maria Santos\",\"contact_number\":\"09925798321\"}', '2026-10-07 06:58:59'),
(39, 6, 6, 'LOGOUT', '{\"logout\":true}', '2026-10-07 06:59:35'),
(40, 6, 6, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-07 07:08:32'),
(41, 6, 6, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:23:43'),
(42, 6, 6, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:26:10'),
(43, 6, 6, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:27:41'),
(44, 6, 6, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:32:12'),
(45, 6, 6, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:38:13'),
(46, 6, 6, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:38:27'),
(47, 6, 6, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:41:48'),
(48, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:44:27'),
(49, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:44:47'),
(50, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:46:45'),
(51, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:53:05'),
(52, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-07 23:53:28'),
(53, NULL, 10, 'RESIDENT_REGISTRATION', '{\"first_name\":\"a\",\"last_name\":\"a\",\"username\":\"resident4\",\"contact_number\":\"09925798321\"}', '2026-10-07 23:57:11'),
(54, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 00:00:49'),
(55, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 00:01:25'),
(56, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 00:05:38'),
(57, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 00:09:43'),
(58, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 00:10:22'),
(59, 2, 2, 'FAILED_LOGIN', '{\"username\":\"officer1\"}', '2026-10-08 00:11:20'),
(60, 2, 2, 'FAILED_LOGIN', '{\"username\":\"officer1\"}', '2026-10-08 00:11:22'),
(61, 2, 2, 'FAILED_LOGIN', '{\"username\":\"officer1\"}', '2026-10-08 00:11:26'),
(62, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 00:11:38'),
(63, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 00:12:20'),
(64, 9, 2, 'PASSWORD_RESET_BY_ADMIN', '{\"target_user_id\":2}', '2026-10-08 00:12:41'),
(65, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 00:12:52'),
(66, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-08 00:13:02'),
(67, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 00:23:55'),
(68, 9, 9, 'PROFILE_UPDATED', '{\"full_name\":\"Maria Santos\",\"contact_number\":\"09925798321\"}', '2026-10-08 00:27:39'),
(69, 9, 9, 'PROFILE_UPDATED', '{\"full_name\":\"Maria Santos\",\"contact_number\":\"09925798321\"}', '2026-10-08 00:30:05'),
(70, 9, 9, 'PROFILE_UPDATED', '{\"full_name\":\"Maria Santos\",\"contact_number\":\"09925798321\"}', '2026-10-08 00:30:35'),
(71, 9, 11, 'OFFICIAL_ACCOUNT_CREATED', '{\"name\":\"Renato Lim\",\"role\":\"CAPTAIN\",\"username\":\"captain1\"}', '2026-10-08 00:54:29'),
(72, 9, 2, 'OFFICIAL_ACCOUNT_UPDATED', '{\"name\":\"Gabriel Garcia\",\"username\":\"officer1\"}', '2026-10-08 00:55:00'),
(73, 9, 2, 'PASSWORD_RESET_BY_ADMIN', '{\"target_user_id\":2}', '2026-10-08 00:55:09'),
(74, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 01:01:23'),
(75, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 01:24:37'),
(76, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 04:07:01'),
(77, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 04:17:53'),
(78, 8, 8, 'LOGIN', '{\"username\":\"resident3\",\"role\":\"RESIDENT\"}', '2026-10-08 04:18:03'),
(79, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 04:41:09'),
(80, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 04:43:28'),
(81, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 04:52:24'),
(82, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 04:53:06'),
(83, 9, NULL, 'SYSTEM_PARAMETER_UPDATED', '{\"type\":\"category\",\"name\":\"Purok 2\"}', '2026-10-08 04:53:27'),
(84, 9, 10, 'RESIDENT_DOCUMENT_VIEWED', '{\"resident_name\":\"a a\"}', '2026-10-08 04:55:19'),
(85, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 04:55:43'),
(86, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 05:10:11'),
(87, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 05:17:28'),
(88, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 05:18:28'),
(89, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 05:19:29'),
(90, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 05:41:55'),
(91, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 05:42:10'),
(92, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 05:43:05'),
(93, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 05:43:15'),
(94, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 05:49:03'),
(95, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 05:51:39'),
(96, 9, 8, 'ACCOUNT_DEACTIVATED', '{\"reason\":\"mmmmm\"}', '2026-10-08 05:51:57'),
(97, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 05:58:28'),
(98, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 05:58:34'),
(99, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 05:58:49'),
(100, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 05:58:50'),
(101, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 05:58:50'),
(102, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 05:58:50'),
(103, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-08 06:00:53'),
(104, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 06:02:21'),
(105, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 06:05:49'),
(106, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 06:06:14'),
(107, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 06:21:30'),
(108, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 06:22:41'),
(109, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 06:22:42'),
(110, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 06:22:43'),
(111, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 06:23:04'),
(112, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 06:27:32'),
(113, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 06:27:55'),
(114, 9, NULL, 'SYSTEM_PARAMETER_ADDED', '{\"type\":\"purok\",\"name\":\"Purok 8\"}', '2026-10-08 06:28:12'),
(115, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 06:28:17'),
(116, 9, 9, 'FAILED_LOGIN', '{\"username\":\"admin1\"}', '2026-10-08 06:28:35'),
(117, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 06:28:40'),
(118, 9, NULL, 'SYSTEM_PARAMETER_DEACTIVATED', '{\"type\":\"purok\",\"id\":28}', '2026-10-08 06:28:50'),
(119, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 06:28:54'),
(120, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 06:29:14'),
(121, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 06:33:45'),
(122, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 12:03:13'),
(123, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 12:25:58'),
(124, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 12:26:07'),
(125, 9, 10, 'RESIDENT_DOCUMENT_VIEWED', '{\"resident_name\":\"a a\"}', '2026-10-08 12:33:14'),
(126, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 12:42:30'),
(127, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 12:51:56'),
(128, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 12:51:59'),
(129, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 13:13:45'),
(130, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 13:14:17'),
(131, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 14:04:25'),
(132, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 14:04:49'),
(133, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 14:18:15'),
(134, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 14:28:31'),
(135, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 14:41:19'),
(136, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 14:41:27'),
(137, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 14:45:34'),
(138, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 14:46:40'),
(139, 5, 5, 'FAILED_LOGIN', '{\"username\":\"resident1\"}', '2026-10-08 15:00:13'),
(140, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-08 15:00:16'),
(141, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-08 15:01:32'),
(142, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-08 15:01:46'),
(143, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-08 15:05:44'),
(144, 5, 5, 'FAILED_LOGIN', '{\"username\":\"resident1\"}', '2026-10-08 15:05:56'),
(145, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-08 15:06:01'),
(146, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-08 15:08:21'),
(147, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-08 23:55:08'),
(148, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-09 00:14:47'),
(149, 11, 11, 'LOGIN', '{\"username\":\"captain1\",\"role\":\"CAPTAIN\"}', '2026-10-09 00:14:56'),
(150, 11, 11, 'LOGOUT', '{\"logout\":true}', '2026-10-09 00:16:07'),
(151, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-09 00:16:40'),
(152, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-09 01:47:47'),
(153, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-09 01:47:55'),
(154, 9, 9, 'PROFILE_UPDATED', '{\"full_name\":\"Maria Santos\",\"contact_number\":\"09925798321\"}', '2026-10-09 01:48:31'),
(155, 9, 9, 'PROFILE_UPDATED', '{\"full_name\":\"Maria Santos\",\"contact_number\":\"09925798321\"}', '2026-10-09 01:48:56'),
(156, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-09 01:48:58'),
(157, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-09 01:49:19'),
(158, 7, 7, 'PROFILE_UPDATED', '{\"full_name\":\"Erycka Ferranco\",\"contact_number\":null}', '2026-10-09 02:19:14'),
(159, 7, NULL, 'INCIDENT_FILED', '{\"reference_number\":\"TRK-2026-009\",\"type\":\"Flooding\"}', '2026-10-09 02:19:46'),
(160, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-09 02:20:56'),
(161, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-09 02:21:08'),
(162, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-09 02:21:26'),
(163, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-09 02:21:37'),
(164, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-09 02:22:29'),
(165, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-09 02:22:38'),
(166, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-09 02:23:16'),
(167, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-09 02:23:24'),
(168, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-09 02:35:14'),
(169, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-09 02:57:16'),
(170, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-09 02:57:45'),
(171, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-09 02:57:54'),
(172, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-09 02:58:24'),
(173, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-09 03:05:25'),
(174, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-09 03:06:09'),
(175, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-09 03:07:01'),
(176, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-09 03:26:04'),
(177, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-09 03:26:17'),
(178, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-09 03:44:43'),
(179, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-09 03:44:53'),
(180, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-10 10:36:14'),
(181, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-10 10:36:40'),
(182, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-10 10:36:47'),
(183, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-10 10:37:17'),
(184, 11, 11, 'LOGIN', '{\"username\":\"captain1\",\"role\":\"CAPTAIN\"}', '2026-10-10 10:37:27'),
(185, 11, NULL, 'dilg_report_generated', '{\"month\":\"2026-10\"}', '2026-10-10 10:38:03'),
(186, 11, 11, 'LOGOUT', '{\"logout\":true}', '2026-10-10 10:38:09'),
(187, 5, 5, 'FAILED_LOGIN', '{\"username\":\"resident1\"}', '2026-10-10 11:10:40'),
(188, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-10 11:10:44'),
(189, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-10 11:11:22'),
(190, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-10 12:35:28'),
(191, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-11 07:49:59'),
(192, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-11 07:50:02'),
(193, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-11 07:50:15'),
(194, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-11 07:54:03'),
(195, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-11 09:04:26'),
(196, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-11 09:19:12'),
(197, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-11 09:19:22'),
(198, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-11 09:21:37'),
(199, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-11 09:28:11'),
(200, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-11 09:59:42'),
(201, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-11 10:05:10'),
(202, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-11 10:19:59'),
(203, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-11 10:34:35'),
(204, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-11 10:41:16'),
(205, 11, 11, 'FAILED_LOGIN', '{\"username\":\"captain1\"}', '2026-10-11 10:41:27'),
(206, 11, 11, 'LOGIN', '{\"username\":\"captain1\",\"role\":\"CAPTAIN\"}', '2026-10-11 10:41:31'),
(207, 11, NULL, 'dilg_report_generated', '{\"month\":\"2026-10\"}', '2026-10-11 10:41:54'),
(208, 11, NULL, 'dilg_report_generated', '{\"month\":\"2026-10\"}', '2026-10-11 10:42:03'),
(209, 11, NULL, 'dilg_report_generated', '{\"month\":\"2026-10\"}', '2026-10-11 10:46:36'),
(210, 11, 11, 'LOGOUT', '{\"logout\":true}', '2026-10-11 11:20:10'),
(211, 11, 11, 'LOGIN', '{\"username\":\"captain1\",\"role\":\"CAPTAIN\"}', '2026-10-11 11:20:23'),
(212, 11, 11, 'LOGOUT', '{\"logout\":true}', '2026-10-11 11:20:42'),
(213, 11, 11, 'LOGIN', '{\"username\":\"captain1\",\"role\":\"CAPTAIN\"}', '2026-10-11 11:23:40'),
(214, 11, 11, 'LOGOUT', '{\"logout\":true}', '2026-10-11 12:59:28'),
(215, 11, 11, 'LOGIN', '{\"username\":\"captain1\",\"role\":\"CAPTAIN\"}', '2026-10-12 12:43:08'),
(216, 11, 11, 'LOGOUT', '{\"logout\":true}', '2026-10-12 13:02:29'),
(217, 2, 2, 'FAILED_LOGIN', '{\"username\":\"officer1\"}', '2026-10-12 13:02:42'),
(218, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-12 13:02:46'),
(219, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-12 13:18:53'),
(220, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-17 09:08:03'),
(221, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-17 09:30:59'),
(222, 5, 5, 'FAILED_LOGIN', '{\"username\":\"resident1\"}', '2026-10-17 09:31:15'),
(223, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-17 09:31:25'),
(224, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-17 09:32:34'),
(225, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-17 09:32:51'),
(226, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-17 09:37:05'),
(227, 11, 11, 'LOGIN', '{\"username\":\"captain1\",\"role\":\"CAPTAIN\"}', '2026-10-17 09:37:14'),
(228, 11, 11, 'LOGOUT', '{\"logout\":true}', '2026-10-17 09:49:43'),
(229, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-17 09:50:00'),
(230, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-17 09:59:39'),
(231, 11, 11, 'LOGIN', '{\"username\":\"captain1\",\"role\":\"CAPTAIN\"}', '2026-10-18 03:40:10'),
(232, 11, 11, 'LOGOUT', '{\"logout\":true}', '2026-10-18 03:41:37'),
(233, NULL, 12, 'RESIDENT_REGISTRATION', '{\"first_name\":\"Olivia\",\"last_name\":\"Cruz\",\"username\":\"resident5\",\"contact_number\":\"09106372068\"}', '2026-10-18 03:44:09'),
(234, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-18 03:44:39'),
(235, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-18 05:06:23'),
(236, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-18 05:12:34'),
(237, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-18 05:12:43'),
(238, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-18 05:13:39'),
(239, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-18 05:13:58'),
(240, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-18 05:14:42'),
(241, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-18 05:14:51'),
(242, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-18 05:21:58'),
(243, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-18 05:22:07'),
(244, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-18 06:17:23'),
(245, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-18 06:23:50'),
(246, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-18 06:24:02'),
(247, NULL, 13, 'RESIDENT_REGISTRATION', '{\"first_name\":\"Erycka\",\"last_name\":\"Ferranco\",\"username\":\"resident6\",\"contact_number\":\"09165571954\"}', '2026-10-18 07:07:57'),
(248, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-18 07:39:11'),
(249, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-18 07:39:20'),
(250, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-18 07:39:31'),
(251, 2, NULL, 'ENCODE_INCIDENT', 'Encoded incident record #IR-20261018-B1CB3.', '2026-10-18 08:12:47'),
(252, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-18 08:45:08'),
(253, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-18 08:45:18'),
(254, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-18 09:28:48'),
(255, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-18 09:29:02'),
(256, 9, NULL, 'EQUIPMENT_ASSIGNED', '{\"equipment_id\":15,\"assigned_to\":\"Gabriel Garcia\"}', '2026-10-18 09:29:39'),
(257, 9, NULL, 'EQUIPMENT_ASSIGNED', '{\"equipment_id\":14,\"assigned_to\":\"Gabriel Garcia\"}', '2026-10-18 09:29:42'),
(258, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-18 09:33:27'),
(259, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-18 09:33:37'),
(260, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-18 10:00:26'),
(261, 11, 11, 'LOGIN', '{\"username\":\"captain1\",\"role\":\"CAPTAIN\"}', '2026-10-18 10:00:34'),
(262, 11, 11, 'LOGOUT', '{\"logout\":true}', '2026-10-18 10:12:22'),
(263, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-18 10:12:41'),
(264, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-18 10:20:33'),
(265, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-18 10:20:43'),
(266, 9, NULL, 'EQUIPMENT_ADDED', '{\"equipment_id\":16,\"name\":\"car\",\"status\":\"AVAILABLE\"}', '2026-10-18 10:21:34'),
(267, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-18 10:22:24'),
(268, 11, 11, 'LOGIN', '{\"username\":\"captain1\",\"role\":\"CAPTAIN\"}', '2026-10-18 10:22:32'),
(269, 11, 11, 'LOGOUT', '{\"logout\":true}', '2026-10-18 10:34:43'),
(270, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-18 10:34:56'),
(271, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-18 10:40:48'),
(272, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-18 10:40:59'),
(273, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-18 10:43:37'),
(274, 7, 7, 'LOGIN', '{\"username\":\"resident2\",\"role\":\"RESIDENT\"}', '2026-10-18 10:43:58'),
(275, 7, 7, 'LOGOUT', '{\"logout\":true}', '2026-10-18 10:58:52'),
(276, 11, 11, 'LOGIN', '{\"username\":\"captain1\",\"role\":\"CAPTAIN\"}', '2026-10-18 10:59:01'),
(277, 11, 11, 'LOGOUT', '{\"logout\":true}', '2026-10-18 12:49:52'),
(278, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-18 12:50:02'),
(279, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-18 13:24:41'),
(280, 9, 9, 'LOGIN', '{\"username\":\"admin1\",\"role\":\"ADMIN\"}', '2026-10-18 13:25:08'),
(281, 9, 13, 'RESIDENT_APPROVED', '{\"message\":\"Approved resident account\"}', '2026-10-18 13:25:24'),
(282, 9, 9, 'LOGOUT', '{\"logout\":true}', '2026-10-18 13:25:43'),
(283, 13, 13, 'LOGIN', '{\"username\":\"resident6\",\"role\":\"RESIDENT\"}', '2026-10-18 13:26:21'),
(284, 13, 13, 'LOGOUT', '{\"logout\":true}', '2026-10-18 13:30:33'),
(285, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-18 13:33:07'),
(286, 2, 2, 'LOGOUT', '{\"logout\":true}', '2026-10-18 14:49:23'),
(287, 13, 13, 'LOGIN', '{\"username\":\"resident6\",\"role\":\"RESIDENT\"}', '2026-10-18 14:49:50'),
(288, 13, 13, 'LOGOUT', '{\"logout\":true}', '2026-10-18 15:50:09'),
(289, 2, 2, 'LOGIN', '{\"username\":\"officer1\",\"role\":\"OFFICER\"}', '2026-10-18 15:50:18');

-- --------------------------------------------------------

--
-- Table structure for table `case_milestones`
--

CREATE TABLE `case_milestones` (
  `id` int(11) NOT NULL,
  `incident_id` int(11) NOT NULL,
  `tracking_id` varchar(30) NOT NULL,
  `status_snapshot` varchar(50) NOT NULL,
  `officer_in_charge` varchar(120) DEFAULT NULL,
  `deployed_unit` varchar(100) DEFAULT NULL,
  `action_note` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `case_findings` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `observations` text NOT NULL,
  `persons_contacted` text DEFAULT NULL,
  `action_taken` text NOT NULL,
  `findings` text NOT NULL,
  `recommended_next_steps` text DEFAULT NULL,
  `submitted_by_user_id` int(11) DEFAULT NULL,
  `submitted_by_name` varchar(120) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_case_findings_incident_created` (`incident_id`, `created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `case_resolutions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `outcome` enum('RESOLVED_THROUGH_INTERVENTION','SETTLED_THROUGH_MEDIATION','REFERRED_TO_ANOTHER_AUTHORITY','WITHDRAWN','OTHER') NOT NULL,
  `findings_reviewed_summary` text NOT NULL,
  `conclusion` text NOT NULL,
  `settlement_terms` text DEFAULT NULL,
  `remaining_obligations` text DEFAULT NULL,
  `follow_up_at` datetime DEFAULT NULL,
  `resulting_status` enum('IN_PROGRESS','RESOLVED','CLOSED') NOT NULL,
  `resolved_by_user_id` int(11) DEFAULT NULL,
  `resolved_by_name` varchar(120) NOT NULL,
  `resolved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_case_resolutions_incident_created` (`incident_id`, `resolved_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `case_workflow_closures` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `resolution_id` bigint(20) UNSIGNED NOT NULL,
  `outcome` varchar(50) NOT NULL,
  `findings_reviewed_summary` text NOT NULL,
  `conclusion` text NOT NULL,
  `closed_by_user_id` int(11) DEFAULT NULL,
  `closed_by_name` varchar(120) NOT NULL,
  `closed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_case_workflow_closures_incident_created` (`incident_id`, `closed_at`, `id`),
  KEY `idx_case_workflow_closures_resolution` (`resolution_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `case_milestones`
--

INSERT INTO `case_milestones` (`id`, `incident_id`, `tracking_id`, `status_snapshot`, `officer_in_charge`, `deployed_unit`, `action_note`, `created_at`) VALUES
(1, 1, 'TRK-2026-001', 'PENDING', NULL, NULL, 'Initial report logged as Noise Disturbance. Priority: Moderate (Score: 40).', '2026-09-22 07:10:40'),
(2, 2, 'TRK-2026-002', 'CRITICAL', NULL, NULL, 'Initial report logged as Property Dispute. Priority: Critical (Score: 85).', '2026-09-22 07:11:53'),
(3, 3, 'TRK-2026-003', 'PENDING', NULL, NULL, 'Initial report logged as Physical Altercation. Priority: Low (Score: 20).', '2026-09-22 07:12:30'),
(4, 4, 'TRK-2026-004', 'PENDING', NULL, NULL, 'Initial report logged as Others. Priority: Moderate (Score: 40).', '2026-09-22 07:24:48'),
(5, 2, 'TRK-2026-002', 'IN_PROGRESS', 'Officer Maria Santos', NULL, 'Quick dispatch confirmed. Officer Maria Santos assigned to respond.', '2026-10-05 12:26:21'),
(6, 5, 'TRK-2026-005', 'PENDING', NULL, NULL, 'Initial report logged as Vandalism. Priority: Low (Score: 20).', '2026-10-05 12:28:07'),
(7, 5, 'TRK-2026-005', 'FOR_RESOLUTION', NULL, NULL, 'Status updated to FOR RESOLUTION from the case details drawer.', '2026-10-05 12:29:01'),
(8, 5, 'TRK-2026-005', 'RESOLVED', NULL, NULL, 'Status updated to RESOLVED from the case details drawer.', '2026-10-05 12:29:08'),
(9, 5, 'TRK-2026-005', 'RESOLVED', NULL, NULL, 'Case closed as resolved from the case details drawer.', '2026-10-05 12:29:14'),
(10, 3, 'TRK-2026-003', 'IN_PROGRESS', 'Officer Perez', NULL, 'Quick dispatch confirmed. Officer Perez assigned to respond.', '2026-10-05 12:39:25'),
(11, 6, 'TRK-2026-006', 'PENDING', NULL, NULL, 'Initial report logged as Others. Priority: High (Score: 65).', '2026-10-05 12:55:14'),
(12, 7, 'TRK-2026-007', 'PENDING', NULL, NULL, 'Initial report submitted via Resident Portal. Priority: High.', '2026-10-09 00:58:19'),
(13, 8, 'TRK-2026-008', 'PENDING', NULL, NULL, 'Initial report submitted via Resident Portal. Priority: High.', '2026-10-09 00:58:19'),
(14, 9, 'TRK-2026-009', 'PENDING', NULL, NULL, 'Initial report logged as Flooding. Priority: High (Score: 65).', '2026-10-09 02:19:46'),
(15, 9, 'TRK-2026-009', 'IN_PROGRESS', NULL, NULL, 'Status updated to IN PROGRESS from the case details drawer.', '2026-10-09 02:23:01'),
(16, 9, 'TRK-2026-009', 'IN_PROGRESS', NULL, NULL, 'Status updated to IN PROGRESS from the case details drawer.', '2026-10-09 02:23:01'),
(17, 7, 'TRK-2026-007', 'IN_PROGRESS', 'Gabriel Garcia', NULL, 'Accepted from desk triage into official blotter.', '2026-10-10 12:36:00'),
(18, 2, 'TRK-2026-002', 'DISPATCHED', 'Gabriel Garcia', 'Tanod Ramos', 'dsdsds', '2026-10-11 09:04:44'),
(19, 2, 'TRK-2026-002', 'DISPATCHED', 'Gabriel Garcia', 'Tanod Ramos', 'dsdsds', '2026-10-11 09:05:03'),
(20, 8, 'TRK-2026-008', 'IN_PROGRESS', 'Gabriel Garcia', NULL, 'Accepted from desk triage into official blotter.', '2026-10-11 09:18:23'),
(21, 6, 'TRK-2026-006', 'IN_PROGRESS', 'Gabriel Garcia', NULL, 'Accepted from desk triage into official blotter.', '2026-10-11 09:18:27'),
(22, 1, 'TRK-2026-001', 'IN_PROGRESS', 'Gabriel Garcia', NULL, 'Accepted from desk triage into official blotter.', '2026-10-11 09:18:28'),
(23, 2, 'TRK-2026-002', 'DISPATCHED', 'Gabriel Garcia', 'Tanod Ramos', 'jisjijisjdsi', '2026-10-11 09:18:58'),
(24, 2, 'TRK-2026-002', 'DISPATCHED', 'Gabriel Garcia', 'Tanod Ramos', 'jisjijisjdsi', '2026-10-11 09:19:08'),
(25, 9, 'TRK-2026-009', 'DISPATCHED', 'Gabriel Garcia', 'Unit Alpha (Tanod Ramon Diaz, Tanod Elena Flores)', 'Immediate responder dispatched for on-site investigation and peace preservation.', '2026-10-11 09:59:11'),
(26, 8, 'TRK-2026-008', 'DISPATCHED', 'Gabriel Garcia', 'Tanod Patrol 2 (Tanod Elena Flores)', 'Immediate responder dispatched for on-site investigation and peace preservation.', '2026-10-12 13:17:35'),
(27, 10, 'TRK-2026-010', 'IN_PROGRESS', 'Gabriel Garcia', NULL, 'Walk-in blotter intake recorded by desk officer. Priority: High.', '2026-10-17 09:08:56'),
(28, 11, 'IR-20261018-B1CB3', 'PENDING', 'Gabriel Garcia', NULL, 'Incident encoded. Assessed priority: Low.', '2026-10-18 08:12:47'),
(29, 11, 'IR-20261018-B1CB3', 'IN_PROGRESS', 'Gabriel Garcia', NULL, 'Accepted from desk triage into official blotter.', '2026-10-18 08:13:23'),
(30, 11, 'IR-20261018-B1CB3', 'DISPATCHED', 'Gabriel Garcia', 'Tanod Patrol 1 (Tanod Ramon Diaz)', 'Immediate responder dispatched for on-site investigation and peace preservation.', '2026-10-18 08:14:31'),
(31, 11, 'IR-20261018-B1CB3', 'FOR_RESOLUTION', 'Capt. Eduardo Reyes', NULL, 'Endorsed to Katarungang Pambarangay. Mediation scheduled on 2026-10-18T16:14 with Capt. Eduardo Reyes.', '2026-10-18 08:14:45'),
(32, 11, 'IR-20261018-B1CB3', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 08:15:08'),
(33, 9, 'TRK-2026-009', 'FOR_RESOLUTION', 'Capt. Eduardo Reyes', NULL, 'Endorsed to Katarungang Pambarangay. Mediation scheduled on 2026-10-18T16:26 with Capt. Eduardo Reyes.', '2026-10-18 08:26:23'),
(34, 9, 'TRK-2026-009', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 08:26:30'),
(35, 3, 'TRK-2026-003', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 08:26:32'),
(36, 3, 'TRK-2026-003', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 08:26:34'),
(37, 2, 'TRK-2026-002', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 08:26:37'),
(38, 10, 'TRK-2026-010', 'FOR_RESOLUTION', 'Capt. Eduardo Reyes', NULL, 'Endorsed to Katarungang Pambarangay. Mediation scheduled on 2026-10-18T17:34 with Capt. Eduardo Reyes.', '2026-10-18 09:34:18'),
(39, 10, 'TRK-2026-010', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 09:34:25'),
(40, 3, 'TRK-2026-003', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 09:34:27'),
(41, 7, 'TRK-2026-007', 'DISPATCHED', 'Gabriel Garcia', 'Tanod Patrol 2 (Tanod Elena Flores)', 'Immediate responder dispatched for on-site investigation and peace preservation.', '2026-10-18 09:35:01'),
(42, 6, 'TRK-2026-006', 'DISPATCHED', 'Gabriel Garcia', 'Tanod Patrol 2 (Tanod Elena Flores)', 'Immediate responder dispatched for on-site investigation and peace preservation.', '2026-10-18 09:35:05'),
(43, 4, 'TRK-2026-004', 'FOR_RESOLUTION', 'Capt. Eduardo Reyes', NULL, 'Endorsed to Katarungang Pambarangay. Mediation scheduled on 2026-10-18T17:35 with Capt. Eduardo Reyes.', '2026-10-18 09:35:54'),
(44, 1, 'TRK-2026-001', 'FOR_RESOLUTION', 'Capt. Eduardo Reyes', NULL, 'Endorsed to Katarungang Pambarangay. Mediation scheduled on 2026-10-18T17:35 with Capt. Eduardo Reyes.', '2026-10-18 09:36:02'),
(45, 4, 'TRK-2026-004', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 09:36:07'),
(46, 3, 'TRK-2026-003', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 09:36:08'),
(47, 1, 'TRK-2026-001', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 09:36:12'),
(48, 2, 'TRK-2026-002', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 09:36:13'),
(49, 3, 'TRK-2026-003', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 09:36:15'),
(50, 7, 'TRK-2026-007', 'DISPATCHED', 'Gabriel Garcia', 'Tanod Patrol (Elena Flores)', 'Immediate responder dispatched for on-site investigation and peace preservation.', '2026-10-18 09:59:30'),
(51, 7, 'TRK-2026-007', 'FOR_RESOLUTION', 'Capt. Eduardo Reyes', NULL, 'Endorsed to Katarungang Pambarangay. Mediation scheduled on 2026-10-18T18:00 with Capt. Eduardo Reyes.', '2026-10-18 10:00:11'),
(52, 7, 'TRK-2026-007', 'RESOLVED', 'Gabriel Garcia', NULL, 'Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.', '2026-10-18 10:00:19'),
(53, 12, 'IR-20261018-5BD14', 'PENDING', 'Erycka Mae Ferranco', NULL, 'Incident encoded by resident. Assessed priority: Moderate.', '2026-10-18 15:49:53'),
(54, 12, 'IR-20261018-5BD14', 'IN_PROGRESS', 'Gabriel Garcia', NULL, 'Accepted from desk triage into official blotter.', '2026-10-18 15:51:22');

-- --------------------------------------------------------

--
-- Table structure for table `endorsements`
--

CREATE TABLE `endorsements` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'LUPON_CERTIFICATION',
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `referred_by_user_id` int(11) DEFAULT NULL,
  `endorsed_by_user_id` int(11) DEFAULT NULL,
  `referred_at` datetime DEFAULT current_timestamp(),
  `endorsed_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `endorsements`
--

INSERT INTO `endorsements` (`id`, `case_id`, `type`, `status`, `referred_by_user_id`, `endorsed_by_user_id`, `referred_at`, `endorsed_at`, `remarks`) VALUES
(1, 1, 'LUPON_CERTIFICATION', 'ENDORSED', NULL, 11, '2026-10-11 18:45:51', '2026-10-18 18:00:49', NULL),
(2, 3, 'LUPON_CERTIFICATION', 'ENDORSED', NULL, 11, '2026-10-11 18:45:51', '2026-10-18 18:00:51', NULL),
(4, 1, 'LUPON_CERTIFICATION', 'ENDORSED', NULL, 11, '2026-10-11 19:31:39', '2026-10-18 18:00:53', NULL),
(5, 3, 'LUPON_CERTIFICATION', 'ENDORSED', NULL, 11, '2026-10-11 19:31:39', '2026-10-18 18:00:55', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `equipment_history`
--

CREATE TABLE `equipment_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `equipment_id` int(10) UNSIGNED NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `equipment_history`
--

INSERT INTO `equipment_history` (`id`, `equipment_id`, `actor_user_id`, `action`, `details`, `created_at`) VALUES
(3, 16, 9, 'EQUIPMENT_ADDED', 'car · AVAILABLE', '2026-10-18 10:21:34');

-- --------------------------------------------------------

--
-- Table structure for table `equipment_items`
--

CREATE TABLE `equipment_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `category` enum('PATROL_EQUIPMENT','COMMUNICATION','VEHICLE') NOT NULL DEFAULT 'PATROL_EQUIPMENT',
  `serial_number` varchar(120) DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `condition_label` varchar(60) NOT NULL DEFAULT 'Good',
  `status` varchar(50) NOT NULL DEFAULT 'AVAILABLE',
  `assigned_user_id` int(11) DEFAULT NULL,
  `vehicle_type` varchar(100) DEFAULT NULL,
  `plate_number` varchar(30) DEFAULT NULL,
  `acquired_at` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `equipment_items`
--

INSERT INTO `equipment_items` (`id`, `name`, `category`, `serial_number`, `quantity`, `condition_label`, `status`, `assigned_user_id`, `vehicle_type`, `plate_number`, `acquired_at`, `notes`, `created_at`, `updated_at`) VALUES
(16, 'car', 'PATROL_EQUIPMENT', '1', 1, 'Good', 'AVAILABLE', NULL, NULL, NULL, '2026-10-18', NULL, '2026-10-18 10:21:34', '2026-10-18 10:21:34');

-- --------------------------------------------------------

--
-- Table structure for table `incident_categories`
--

CREATE TABLE `incident_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `incident_categories`
--

INSERT INTO `incident_categories` (`id`, `name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Theft', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(2, 'Purok 2', 1, '2026-10-05 11:03:16', '2026-10-08 04:53:26'),
(3, 'Vandalism', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(4, 'Domestic Dispute', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(5, 'Noise Complaint', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(6, 'Road/Traffic Incident', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(7, 'Fire', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(8, 'Flood', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(9, 'Other', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(10, 'Noise Disturbance', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(11, 'Property Dispute', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(12, 'Physical Altercation', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(13, 'Illegal Dumping', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(14, 'Others', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16');

-- --------------------------------------------------------

--
-- Table structure for table `incident_reports`
--

CREATE TABLE `incident_reports` (
  `id` int(11) NOT NULL,
  `reference_number` varchar(30) NOT NULL,
  `complainant_name` varchar(120) NOT NULL,
  `complainant_phone` varchar(30) NOT NULL,
  `incident_type` varchar(100) NOT NULL,
  `narrative_description` text NOT NULL,
  `incident_datetime` datetime NOT NULL,
  `purok` varchar(60) NOT NULL,
  `latitude` decimal(10,8) NOT NULL DEFAULT 14.54530000,
  `longitude` decimal(11,8) NOT NULL DEFAULT 120.57390000,
  `scenario_type` enum('WALK_IN','ONLINE_PORTAL','EMERGENCY') DEFAULT 'WALK_IN',
  `verification_level` enum('VERIFIED','UNVERIFIED') DEFAULT 'VERIFIED',
  `ai_urgency_score` decimal(4,2) DEFAULT 0.00,
  `priority_level` enum('Low','Moderate','High','Critical') DEFAULT 'Moderate',
  `ai_recommendation` text DEFAULT NULL,
  `ai_analysis_json` longtext DEFAULT NULL,
  `legal_routing` varchar(100) DEFAULT NULL,
  `weapon_detected` varchar(50) DEFAULT 'NONE',
  `vawc_minor_flag` tinyint(1) DEFAULT 0,
  `status` enum('PENDING','IN_PROGRESS','DISPATCHED','FOR_RESOLUTION','RESOLVED','CLOSED','CRITICAL','REJECTED') DEFAULT 'PENDING',
  `assigned_officer_id` int(11) DEFAULT NULL,
  `assigned_officer_name` varchar(120) DEFAULT NULL,
  `deployed_unit` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `incident_reports`
--

INSERT INTO `incident_reports` (`id`, `reference_number`, `complainant_name`, `complainant_phone`, `incident_type`, `narrative_description`, `incident_datetime`, `purok`, `latitude`, `longitude`, `scenario_type`, `verification_level`, `ai_urgency_score`, `priority_level`, `ai_recommendation`, `ai_analysis_json`, `legal_routing`, `weapon_detected`, `vawc_minor_flag`, `status`, `assigned_officer_id`, `assigned_officer_name`, `deployed_unit`, `created_at`, `updated_at`) VALUES
(1, 'TRK-2026-001', 'Ligaya', '09318091210', 'Noise Disturbance', 'a', '2026-09-22 09:10:40', 'Sitio Masaya', 14.54530000, 120.57390000, 'WALK_IN', 'VERIFIED', 40.00, 'Moderate', 'Case settled amicably during mediation.', NULL, NULL, 'NONE', 0, 'RESOLVED', NULL, NULL, 'Capt. Eduardo Reyes', '2026-09-22 07:10:40', '2026-10-18 09:36:12'),
(2, 'TRK-2026-002', 'Ligaya', '09318091210', 'Property Dispute', 'm', '2026-09-22 09:11:53', 'Sitio Masaya', 14.54530000, 120.57390000, 'WALK_IN', 'VERIFIED', 85.00, 'Critical', 'Case settled amicably during mediation.', NULL, NULL, 'NONE', 0, 'RESOLVED', NULL, 'Officer Maria Santos', 'Tanod Ramos', '2026-09-22 07:11:53', '2026-10-18 08:26:37'),
(3, 'TRK-2026-003', 'ligaya', '09318091210', 'Physical Altercation', 'gfhgf', '2026-09-22 09:12:30', 'Purok 3', 14.54530000, 120.57390000, 'WALK_IN', 'VERIFIED', 20.00, 'Low', 'Case settled amicably during mediation.', NULL, NULL, 'NONE', 0, 'RESOLVED', NULL, 'Officer Perez', NULL, '2026-09-22 07:12:30', '2026-10-18 08:26:32'),
(4, 'TRK-2026-004', 'a', 'a', 'Others', 'a', '2026-09-22 09:24:47', 'Purok 1', 14.54530000, 120.57390000, 'WALK_IN', 'VERIFIED', 40.00, 'Moderate', 'Case settled amicably during mediation.', NULL, NULL, 'NONE', 0, 'RESOLVED', NULL, NULL, 'Capt. Eduardo Reyes', '2026-09-22 07:24:47', '2026-10-18 09:36:07'),
(5, 'TRK-2026-005', 'Ligaya', '09999927864', 'Vandalism', 'An unknown individual crashed into our backyard and started destroying our rice stocks.', '2026-10-05 14:28:07', 'Purok 1', 14.54530000, 120.57390000, 'WALK_IN', 'VERIFIED', 20.00, 'Low', 'Standard administrative intake. Log for regular review.', '{\"incident_type\":\"Others\",\"priority_level\":\"Moderate\",\"ai_urgency_score\":0.4,\"legal_routing\":\"Standard Desk Blotter Recording\",\"weapon_flag\":\"NONE\",\"vawc_minor_flag\":false,\"medical_flag\":\"None indicated.\",\"substance_flag\":\"None indicated.\",\"detected_keywords\":[],\"trigger_excerpts\":[\"An unknown individual crashed into our backyard and started destroying our rice stocks.\"],\"reasoning\":\"Offline rule-based fallback applied due to API connection timeout.\",\"ai_recommendation\":\"Proceed with standard desk intake and issue appropriate summons.\"}', 'Standard Desk Blotter Recording', 'NONE', 0, 'RESOLVED', NULL, NULL, NULL, '2026-10-05 12:28:07', '2026-10-18 13:23:56'),
(6, 'TRK-2026-006', 'abc', '09999927864', 'Others', 'acsafiufhuianfaoiajoi', '2026-10-05 14:55:14', 'Purok 2', 14.54530000, 120.57390000, 'WALK_IN', 'VERIFIED', 65.00, 'High', 'Unit Dispatched: Tanod Patrol 2 (Tanod Elena Flores) assigned for on-site assessment.', '{\"incident_type\":\"Others\",\"priority_level\":\"Moderate\",\"ai_urgency_score\":0.4,\"legal_routing\":\"Standard Desk Blotter Recording\",\"weapon_flag\":\"NONE\",\"vawc_minor_flag\":false,\"medical_flag\":\"None indicated.\",\"substance_flag\":\"None indicated.\",\"detected_keywords\":[],\"trigger_excerpts\":[\"acsafiufhuianfaoiajoi\"],\"reasoning\":\"Offline rule-based fallback applied due to API connection timeout.\",\"ai_recommendation\":\"Proceed with standard desk intake and issue appropriate summons.\"}', 'Standard Desk Blotter Recording', 'NONE', 0, '', NULL, NULL, 'Tanod Patrol 2 (Tanod Elena Flores)', '2026-10-05 12:55:14', '2026-10-18 13:33:38'),
(7, 'TRK-2026-007', 'Erycka Ferranco', '09106372068', 'Noise Disturbance', 'last night the neighbors blasted their music on max volume, we asked to kindly tone it down but they refused and began singing karaoke\n\n[Location Specifics: Lot 51-52 Batol St.]', '2026-10-09 08:58:19', 'Purok 2', 14.54530000, 120.57390000, 'ONLINE_PORTAL', 'VERIFIED', 65.00, 'High', 'Case settled amicably during mediation.', NULL, NULL, 'NONE', 0, 'RESOLVED', NULL, NULL, 'Capt. Eduardo Reyes', '2026-10-09 00:58:19', '2026-10-18 10:00:19'),
(8, 'TRK-2026-008', 'Erycka Ferranco', '09106372068', 'Noise Disturbance', 'last night the neighbors blasted their music on max volume, we asked to kindly tone it down but they refused and began singing karaoke\n\n[Location Specifics: Lot 51-52 Batol St.]', '2026-10-09 08:58:19', 'Purok 2', 14.54530000, 120.57390000, 'ONLINE_PORTAL', 'VERIFIED', 65.00, 'High', 'Unit Dispatched: Tanod Patrol 2 (Tanod Elena Flores) assigned for on-site assessment.', NULL, NULL, 'NONE', 0, '', NULL, NULL, 'Tanod Patrol 2 (Tanod Elena Flores)', '2026-10-09 00:58:19', '2026-10-12 13:17:35'),
(9, 'TRK-2026-009', 'Erycka Ferranco', '09106372068', 'Flooding', 'kdowkdowdowkdoko\n\n[Location Specifics: Lot 51-52 Batol St.]', '2026-10-09 10:19:46', 'Purok 2', 14.54530000, 120.57390000, 'ONLINE_PORTAL', 'VERIFIED', 65.00, 'High', 'Case settled amicably during mediation.', NULL, NULL, 'NONE', 0, 'RESOLVED', NULL, NULL, 'Capt. Eduardo Reyes', '2026-10-09 02:19:46', '2026-10-18 08:26:30'),
(10, 'TRK-2026-010', 'Marites Ramos', '09999927864', 'Noise Disturbance', 'Karaoke Noise\n\n[Location: Batol St.]', '2026-10-17 17:08:56', 'Purok 5', 14.54530000, 120.57390000, 'WALK_IN', 'VERIFIED', 0.00, 'High', 'Case settled amicably during mediation.', '{\"incident_type\":\"Noise Disturbance\",\"priority_level\":\"Low\",\"ai_urgency_score\":0.15,\"legal_routing\":\"Standard Desk Blotter Recording\",\"weapon_flag\":\"NONE\",\"vawc_minor_flag\":false,\"medical_flag\":\"None indicated.\",\"substance_flag\":\"None indicated.\",\"detected_keywords\":[\"karaoke\",\"noise\"],\"trigger_excerpts\":[\"Karaoke Noise\\n\\n[Location: Batol St.]\"],\"reasoning\":\"Offline rule-based fallback applied due to API connection timeout.\",\"ai_recommendation\":\"Proceed with standard desk intake and issue appropriate summons.\"}', 'Standard Desk Blotter Recording', 'NONE', 0, 'RESOLVED', NULL, NULL, 'Capt. Eduardo Reyes', '2026-10-17 09:08:56', '2026-10-18 13:33:49'),
(11, 'IR-20261018-B1CB3', 'a', '09165571954', 'Noise Disturbance', 'Last night, the neighbors from lot 4 were blasting karaoke.', '2026-10-18 16:12:46', 'Purok 3', 14.54530000, 120.57390000, 'WALK_IN', 'VERIFIED', 0.15, 'Low', 'Case settled amicably during mediation.', '{\"incident_type\":\"Noise Disturbance\",\"priority_level\":\"Low\",\"ai_urgency_score\":0.15,\"legal_routing\":\"Standard Desk Blotter Recording\",\"weapon_flag\":\"NONE\",\"vawc_minor_flag\":false,\"medical_flag\":\"None indicated.\",\"substance_flag\":\"None indicated.\",\"detected_keywords\":[\"karaoke\",\"noise\"],\"trigger_excerpts\":[\"Last night, the neighbors from lot 4 were blasting karaoke.\"],\"reasoning\":\"Offline rule-based fallback applied due to API connection timeout.\",\"ai_recommendation\":\"Proceed with standard desk intake and issue appropriate summons.\"}', 'Standard Desk Blotter Recording', 'NONE', 0, 'RESOLVED', 2, 'Gabriel Garcia', 'Capt. Eduardo Reyes', '2026-10-18 08:12:46', '2026-10-18 13:22:49'),
(12, 'IR-20261018-5BD14', 'Erycka Mae Ferranco', '09165571954', 'General Incident', 'a man was stabbed in front of our gate. He\'s bleeding heavily while carrying a knife which he used for self defense. urgent help needed\n[Landmark/Street: Lot 2, Batol St.]\n[Location: Lot 2, Batol St.]', '2026-10-18 23:49:53', 'Purok 2', 14.54530000, 120.57390000, 'WALK_IN', 'VERIFIED', 0.10, 'Moderate', 'Pending desk review', '{\"incident_type\":\"Others\",\"priority_level\":\"Moderate\",\"ai_urgency_score\":0.4,\"legal_routing\":\"Standard Desk Blotter Recording\",\"weapon_flag\":\"NONE\",\"vawc_minor_flag\":false,\"medical_flag\":\"None indicated.\",\"substance_flag\":\"None indicated.\",\"detected_keywords\":[],\"trigger_excerpts\":[\"a man was stabbed in front of our gate. He\'s bleeding heavily while carrying a knife which he used for self defense. urgent help needed\\n[Landmark\\/Street: Lot 2, Batol St.]\\n[Location: Lot 2, Batol St.]\"],\"reasoning\":\"Offline rule-based fallback applied due to API connection timeout.\",\"ai_recommendation\":\"Proceed with standard desk intake and issue appropriate summons.\"}', 'Standard Desk Blotter Recording', 'NONE', 0, 'IN_PROGRESS', 13, 'Erycka Mae Ferranco', NULL, '2026-10-18 15:49:53', '2026-10-18 15:51:22');

-- --------------------------------------------------------

--
-- Table structure for table `lupon_cases`
--

CREATE TABLE `lupon_cases` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `stage` varchar(50) DEFAULT 'MEDIATION',
  `next_hearing` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'ONGOING',
  `parties` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_puroks`
--

CREATE TABLE `system_puroks` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_puroks`
--

INSERT INTO `system_puroks` (`id`, `name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Purok 1', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(2, 'Purok 2', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(3, 'Purok 3', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(4, 'Purok 4', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(5, 'Sitio Masaya', 1, '2026-10-05 11:03:16', '2026-10-05 11:03:16'),
(16, 'Purok 5', 1, '2026-10-07 03:06:55', '2026-10-07 03:06:55'),
(17, 'Purok 6', 1, '2026-10-07 03:06:55', '2026-10-07 03:06:55'),
(18, 'Purok 7', 1, '2026-10-07 03:06:55', '2026-10-07 03:06:55'),
(28, 'Purok 8', 0, '2026-10-08 06:28:12', '2026-10-08 06:28:50');

-- --------------------------------------------------------

--
-- Table structure for table `tanod_personnel`
--

CREATE TABLE `tanod_personnel` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `station` varchar(120) DEFAULT 'Barangay Base',
  `status` enum('AVAILABLE','OFF_DUTY','DEPLOYED') NOT NULL DEFAULT 'AVAILABLE',
  `contact_number` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tanod_personnel`
--

INSERT INTO `tanod_personnel` (`id`, `full_name`, `station`, `status`, `contact_number`, `created_at`) VALUES
(1, 'Ramon Diaz', 'Barangay Base', 'OFF_DUTY', NULL, '2026-10-18 09:31:54'),
(2, 'Elena Flores', 'Patrol 1', 'DEPLOYED', NULL, '2026-10-18 09:31:54'),
(3, 'Nestor Lim', 'Purok 2', 'AVAILABLE', NULL, '2026-10-18 09:31:54'),
(4, 'Lito Navarro', 'Zone 3', 'AVAILABLE', NULL, '2026-10-18 09:31:54'),
(5, 'Mario Aquino', 'Base Station', 'AVAILABLE', NULL, '2026-10-18 09:31:54');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email_or_phone` varchar(100) NOT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `user_type` enum('RESIDENT','OFFICER','ADMIN','CAPTAIN') DEFAULT 'RESIDENT',
  `status` varchar(50) NOT NULL DEFAULT 'ACTIVE',
  `authorization_status` varchar(50) NOT NULL DEFAULT 'PENDING_VERIFICATION',
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `profile_picture` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `purok` varchar(100) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `proof_of_residency` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `email_or_phone`, `contact_number`, `password_hash`, `user_type`, `status`, `authorization_status`, `rejection_reason`, `created_at`, `profile_picture`, `address`, `purok`, `date_of_birth`, `first_name`, `last_name`, `proof_of_residency`) VALUES
(2, 'Gabriel Garcia', 'officer1', 'officer1', '09925798321', '$2y$10$.rEvumsE/OioN9Z7wXQJVu4tRfTV.2/cnrCLXqLeFnpDoJScogFDO', 'OFFICER', 'ACTIVE', 'AUTHORIZED', NULL, '2026-10-05 11:05:47', 'uploads/profiles/officer_2_2c603719020c.jpg', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 'Erycka Ferranco', 'resident1', 'resident1', '09106372068', '$2y$10$NXCattJUwn6qlTFE8zCVnefHPLnsvpLzATFg7sudhhhD5yxMeo8XG', 'RESIDENT', 'ACTIVE', 'AUTHORIZED', NULL, '2026-10-07 02:35:38', NULL, 'OHIO ST. BLK #10, LOT 51-52, ROOSEVELT PARK VILLAGE, BRGY. SAN PABLO (BULATE)', 'Purok 1', '2026-10-07', NULL, NULL, 'uploads/residency/70e776139be229d6fb5acc388b7b6c9a43a42bc6.jpg'),
(7, 'Erycka Ferranco', 'resident2', 'resident2', NULL, '$2y$10$g0FHoL4CDzBRwtJQIQbSaOdkn2lt463zTfZvb/po1nLUNWezi5qp2', 'RESIDENT', 'ACTIVE', 'AUTHORIZED', NULL, '2026-10-07 03:11:41', 'uploads/profiles/resident-7-91c6898f3547ba21.png', 'OHIO ST. BLK #10, LOT 51-52, ROOSEVELT PARK VILLAGE, BRGY. SAN PABLO (BULATE)', 'Purok 2', '2026-10-07', NULL, NULL, 'uploads/residency/1956ce551647ed7e30a74e9e84a113df62e74f9e.jpg'),
(8, 'Erycka Ferranco', 'resident3', 'resident3', '09106372068', '$2y$10$ko4uBkrug6D4veUr7Y2DVeSO5hIHKN0bwHnuM9xWaj5ai20R8tOjm', 'RESIDENT', 'INACTIVE', 'DEACTIVATED', 'mmmmm', '2026-10-07 03:34:15', NULL, 'OHIO ST. BLK #10, LOT 51-52, ROOSEVELT PARK VILLAGE, BRGY. SAN PABLO (BULATE)', 'Purok 1', '2026-10-07', NULL, NULL, 'uploads/residency/177ff9c3c9fd9cbb01e4b8513ade663417d71331.jpg'),
(9, 'Maria Santos', 'admin1', 'admin1@balangaylog.local', '09925798321', '$2y$10$DGmjNfQ1j5tYCxErLo/et.pkO2eVrXzTF.yOSZ0UCWRwdL2hoxURi', 'ADMIN', 'ACTIVE', 'AUTHORIZED', NULL, '2026-10-07 23:44:13', 'uploads/profiles/9-be5c357f0325837f.png', NULL, NULL, NULL, NULL, NULL, NULL),
(10, 'a a', 'resident4', 'resident4', '09925798321', '$2y$10$UAnIvu/JBGL8/eQEDtZp7.ySvbXupaOqyORGhowRkB/IX5k2K6dgK', 'RESIDENT', 'PENDING_VERIFICATION', 'PENDING_VERIFICATION', NULL, '2026-10-07 23:57:11', NULL, 'OHIO ST. BLK #10, LOT 51-52, ROOSEVELT PARK VILLAGE, BRGY. SAN PABLO (BULATE)', 'Purok 1', '2026-10-08', NULL, NULL, 'uploads/residency/4b7995c50f7d2dbde7df5d77d48a7afa39300856.png'),
(11, 'Renato Lim', 'captain1', 'captain1', '09925798321', '$2y$10$XP9VNyLWFuSyBxTaw3AVCOowVk3.boW9um.JwEi9B/MxOG6JlxeqK', 'CAPTAIN', 'ACTIVE', 'AUTHORIZED', NULL, '2026-10-08 00:54:29', 'uploads/profiles/captain_11_1791810143.jpg', NULL, NULL, NULL, NULL, NULL, NULL),
(12, 'Olivia Cruz', 'resident5', 'resident5', '09106372068', '$2y$10$11QKndmcJPILo7TB2EKM8.CgteClwyp797LVAS5tDlxpWVyNfkO8a', 'RESIDENT', 'PENDING_VERIFICATION', 'PENDING_VERIFICATION', NULL, '2026-10-18 03:44:09', NULL, 'duale, limay, bataan', 'Purok 3', '2026-10-18', NULL, NULL, 'uploads/residency/d3ca9ab2904154e85b034c3fa8c7d7eacf049a4b.png'),
(13, 'Erycka Mae Ferranco', 'resident6', 'resident6', '09165571954', '$2y$10$38Cwwz.BvnI0n7Kp42oH6.cUww9YV8TMslqTTuCYgxbGVWm6f4Y72', 'RESIDENT', 'ACTIVE', 'AUTHORIZED', NULL, '2026-10-18 07:07:56', 'uploads/profiles/profile_13_1792330107.jpg', 'brgy. duale, limay, bataan', 'Purok 2', '2026-10-18', NULL, NULL, 'uploads/residency/88f3dc1a1cf9dcb7e4cc5a8c5c770a3e36136b21.jpg');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `advisories`
--
ALTER TABLE `advisories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `case_milestones`
--
ALTER TABLE `case_milestones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `incident_id` (`incident_id`);

--
-- Indexes for table `endorsements`
--
ALTER TABLE `endorsements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `equipment_history`
--
ALTER TABLE `equipment_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_equipment_history_item` (`equipment_id`,`created_at`),
  ADD KEY `fk_equipment_history_actor` (`actor_user_id`);

--
-- Indexes for table `equipment_items`
--
ALTER TABLE `equipment_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_equipment_status` (`status`),
  ADD KEY `idx_equipment_assigned_user` (`assigned_user_id`);

--
-- Indexes for table `incident_categories`
--
ALTER TABLE `incident_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_incident_categories_name` (`name`);

--
-- Indexes for table `incident_reports`
--
ALTER TABLE `incident_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`);

--
-- Indexes for table `lupon_cases`
--
ALTER TABLE `lupon_cases`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_puroks`
--
ALTER TABLE `system_puroks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_system_puroks_name` (`name`);

--
-- Indexes for table `tanod_personnel`
--
ALTER TABLE `tanod_personnel`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email_or_phone` (`email_or_phone`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `advisories`
--
ALTER TABLE `advisories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=290;

--
-- AUTO_INCREMENT for table `case_milestones`
--
ALTER TABLE `case_milestones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `endorsements`
--
ALTER TABLE `endorsements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `equipment_history`
--
ALTER TABLE `equipment_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `equipment_items`
--
ALTER TABLE `equipment_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `incident_categories`
--
ALTER TABLE `incident_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `incident_reports`
--
ALTER TABLE `incident_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `lupon_cases`
--
ALTER TABLE `lupon_cases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_puroks`
--
ALTER TABLE `system_puroks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `tanod_personnel`
--
ALTER TABLE `tanod_personnel`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `case_milestones`
--
ALTER TABLE `case_milestones`
  ADD CONSTRAINT `case_milestones_ibfk_1` FOREIGN KEY (`incident_id`) REFERENCES `incident_reports` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `equipment_history`
--
ALTER TABLE `equipment_history`
  ADD CONSTRAINT `fk_equipment_history_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_equipment_history_item` FOREIGN KEY (`equipment_id`) REFERENCES `equipment_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `equipment_items`
--
ALTER TABLE `equipment_items`
  ADD CONSTRAINT `fk_equipment_assigned_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
