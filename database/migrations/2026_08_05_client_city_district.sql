-- City / District on clients for location-based offers
-- (e.g. free plan for Devbhumi Dwarka businesses). Run once.

ALTER TABLE clients
  ADD COLUMN city VARCHAR(100) NULL AFTER address,
  ADD COLUMN district VARCHAR(100) NULL AFTER city;

ALTER TABLE clients
  ADD KEY idx_clients_district (district);
