INSERT INTO staatsangehoerigkeiten (name)
VALUES ('Deutschland')
ON DUPLICATE KEY UPDATE name = VALUES(name);
