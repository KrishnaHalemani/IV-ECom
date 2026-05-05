<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$db = get_db_connection();
ensure_admin_tables($db);

function handle_product_image_upload(string $fieldName, ?string $currentPath = null): ?string
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return $currentPath;
    }

    $file = $_FILES[$fieldName];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return $currentPath;
    }
    if ($error !== UPLOAD_ERR_OK) {
        return $currentPath;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return $currentPath;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $originalName = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return $currentPath;
    }

    $uploadDir = __DIR__ . '/../uploads/products';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $newName = 'product_' . time() . '_' . uniqid('', true) . '.' . $ext;
    $destination = $uploadDir . '/' . $newName;
    if (!move_uploaded_file($tmpName, $destination)) {
        return $currentPath;
    }

    return 'uploads/products/' . $newName;
}

function handle_hero_image_upload(string $fieldName, ?string $currentPath = null): ?string
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return $currentPath;
    }

    $file = $_FILES[$fieldName];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return $currentPath;
    }
    if ($error !== UPLOAD_ERR_OK) {
        return $currentPath;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return $currentPath;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $originalName = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return $currentPath;
    }

    $uploadDir = __DIR__ . '/../uploads/hero';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $newName = 'hero_' . time() . '_' . uniqid('', true) . '.' . $ext;
    $destination = $uploadDir . '/' . $newName;
    if (!move_uploaded_file($tmpName, $destination)) {
        return $currentPath;
    }

    return 'uploads/hero/' . $newName;
}

function build_category_option_rows(array $categories, bool $activeOnly = true): array
{
    $rowsById = [];
    foreach ($categories as $category) {
        $id = (int) ($category['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if ($activeOnly && (int) ($category['is_active'] ?? 0) !== 1) {
            continue;
        }
        $rowsById[$id] = $category;
    }

    $children = [];
    foreach ($rowsById as $id => $category) {
        $parentId = (int) ($category['parent_id'] ?? 0);
        if ($parentId > 0 && isset($rowsById[$parentId])) {
            $children[$parentId][] = $id;
        } else {
            $children[0][] = $id;
        }
    }

    $optionRows = [];
    $visited = [];
    $walk = static function (int $parentId, int $depth) use (&$walk, &$children, &$rowsById, &$optionRows, &$visited): void {
        if (!isset($children[$parentId])) {
            return;
        }

        usort($children[$parentId], static function (int $a, int $b) use ($rowsById): int {
            $nameA = strtolower(trim((string) ($rowsById[$a]['name'] ?? '')));
            $nameB = strtolower(trim((string) ($rowsById[$b]['name'] ?? '')));
            return strcmp($nameA, $nameB);
        });

        foreach ($children[$parentId] as $categoryId) {
            if (isset($visited[$categoryId])) {
                continue;
            }
            $visited[$categoryId] = true;
            $category = $rowsById[$categoryId];
            $name = trim((string) ($category['name'] ?? 'Category'));
            $label = $depth > 0 ? str_repeat('-- ', $depth) . $name : $name;
            $optionRows[] = [
                'id' => $categoryId,
                'label' => $label,
            ];
            $walk($categoryId, $depth + 1);
        }
    };

    $walk(0, 0);

    foreach ($rowsById as $id => $category) {
        if (isset($visited[$id])) {
            continue;
        }
        $name = trim((string) ($category['name'] ?? 'Category'));
        $optionRows[] = ['id' => $id, 'label' => $name];
    }

    return $optionRows;
}

function normalize_display_section(string $value): string
{
    $displaySection = trim($value);
    $allowedDisplaySections = ['home', 'new_arrivals', 'featured', 'none'];
    if (!in_array($displaySection, $allowedDisplaySections, true)) {
        return 'home';
    }

    return $displaySection;
}

function display_section_flags(string $displaySection): array
{
    if ($displaySection === 'new_arrivals') {
        return ['is_featured' => 0, 'is_new' => 1];
    }
    if ($displaySection === 'featured') {
        return ['is_featured' => 1, 'is_new' => 0];
    }

    return ['is_featured' => 0, 'is_new' => 0];
}

$section = (string) ($_GET['section'] ?? 'products');
$allowedSections = ['products', 'categories', 'users', 'orders', 'stock', 'coupons', 'hero', 'homepage_sections', 'content_labels'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'products';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($section === 'categories') {
        if ($action === 'create_category') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $parentId = (int) ($_POST['parent_id'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') {
                admin_flash_set('Category name is required.', 'error');
            } else {
                $slug = make_slug($name);
                $parent = $parentId > 0 ? $parentId : null;
                if ($parent === null) {
                    $stmt = $db->prepare('INSERT INTO categories (name, slug, parent_id, is_active) VALUES (?, ?, NULL, ?)');
                    if ($stmt) {
                        $stmt->bind_param('ssi', $name, $slug, $isActive);
                        $ok = $stmt->execute();
                        $stmt->close();
                        admin_flash_set($ok ? 'Category added.' : 'Could not add category.', $ok ? 'success' : 'error');
                    }
                } else {
                    $stmt = $db->prepare('INSERT INTO categories (name, slug, parent_id, is_active) VALUES (?, ?, ?, ?)');
                    if ($stmt) {
                        $stmt->bind_param('ssii', $name, $slug, $parent, $isActive);
                        $ok = $stmt->execute();
                        $stmt->close();
                        admin_flash_set($ok ? 'Category added.' : 'Could not add category.', $ok ? 'success' : 'error');
                    }
                }
            }
        } elseif ($action === 'update_category') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $parentId = (int) ($_POST['parent_id'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($id > 0 && $name !== '') {
                $slug = make_slug($name);
                if ($parentId === $id) {
                    $parentId = 0;
                }
                if ($parentId > 0) {
                    $stmt = $db->prepare('UPDATE categories SET name = ?, slug = ?, parent_id = ?, is_active = ? WHERE id = ?');
                    if ($stmt) {
                        $stmt->bind_param('ssiii', $name, $slug, $parentId, $isActive, $id);
                        $ok = $stmt->execute();
                        $stmt->close();
                        admin_flash_set($ok ? 'Category updated.' : 'Could not update category.', $ok ? 'success' : 'error');
                    }
                } else {
                    $stmt = $db->prepare('UPDATE categories SET name = ?, slug = ?, parent_id = NULL, is_active = ? WHERE id = ?');
                    if ($stmt) {
                        $stmt->bind_param('ssii', $name, $slug, $isActive, $id);
                        $ok = $stmt->execute();
                        $stmt->close();
                        admin_flash_set($ok ? 'Category updated.' : 'Could not update category.', $ok ? 'success' : 'error');
                    }
                }
            }
        } elseif ($action === 'delete_category') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM categories WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Category deleted.' : 'Could not delete category.', $ok ? 'success' : 'error');
                }
            }
        } elseif ($action === 'bulk_delete_categories') {
            $idsRaw = $_POST['category_ids'] ?? [];
            $ids = [];
            if (is_array($idsRaw)) {
                foreach ($idsRaw as $rawId) {
                    $id = (int) $rawId;
                    if ($id > 0) {
                        $ids[$id] = true;
                    }
                }
            }
            $ids = array_keys($ids);

            if ($ids === []) {
                admin_flash_set('Please select at least one category to delete.', 'error');
            } else {
                $stmt = $db->prepare('DELETE FROM categories WHERE id = ?');
                $deletedCount = 0;
                if ($stmt) {
                    foreach ($ids as $id) {
                        $stmt->bind_param('i', $id);
                        if ($stmt->execute() && $stmt->affected_rows > 0) {
                            $deletedCount++;
                        }
                    }
                    $stmt->close();
                }

                if ($deletedCount > 0) {
                    admin_flash_set($deletedCount . ' categor' . ($deletedCount === 1 ? 'y deleted.' : 'ies deleted.'), 'success');
                } else {
                    admin_flash_set('No selected categories were deleted.', 'error');
                }
            }
        }
        admin_redirect('controls.php', ['section' => 'categories']);
    }

    if ($section === 'products') {
        if ($action === 'create_product') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $sku = trim((string) ($_POST['sku'] ?? ''));
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $price = (float) ($_POST['price'] ?? 0);
            $stock = (int) ($_POST['stock_qty'] ?? 0);
            $description = trim((string) ($_POST['description'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $displaySection = normalize_display_section((string) ($_POST['display_section'] ?? 'home'));
            $displayFlags = display_section_flags($displaySection);
            $isFeatured = (int) $displayFlags['is_featured'];
            $isNew = (int) $displayFlags['is_new'];
            $imagePath = handle_product_image_upload('image_file', '');

            if ($name === '' || $sku === '') {
                admin_flash_set('Product name and SKU are required.', 'error');
            } else {
                $cat = $categoryId > 0 ? $categoryId : null;
                $stmt = $db->prepare(
                    'INSERT INTO products (category_id, name, sku, image_path, description, price, stock_qty, is_active, is_featured, is_new, display_section)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($stmt) {
                    $stmt->bind_param('issssdiiiis', $cat, $name, $sku, $imagePath, $description, $price, $stock, $isActive, $isFeatured, $isNew, $displaySection);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Product added.' : 'Could not add product (SKU must be unique).', $ok ? 'success' : 'error');
                }
            }
        } elseif ($action === 'update_product') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $sku = trim((string) ($_POST['sku'] ?? ''));
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $price = (float) ($_POST['price'] ?? 0);
            $stock = (int) ($_POST['stock_qty'] ?? 0);
            $description = trim((string) ($_POST['description'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $displaySection = normalize_display_section((string) ($_POST['display_section'] ?? 'home'));
            $displayFlags = display_section_flags($displaySection);
            $isFeatured = (int) $displayFlags['is_featured'];
            $isNew = (int) $displayFlags['is_new'];
            $currentImage = trim((string) ($_POST['current_image_path'] ?? ''));
            $imagePath = handle_product_image_upload('image_file', $currentImage);

            if ($id > 0 && $name !== '' && $sku !== '') {
                $cat = $categoryId > 0 ? $categoryId : null;
                $stmt = $db->prepare(
                    'UPDATE products
                     SET category_id = ?, name = ?, sku = ?, image_path = ?, description = ?, price = ?, stock_qty = ?, is_active = ?, is_featured = ?, is_new = ?, display_section = ?
                     WHERE id = ?'
                );
                if ($stmt) {
                    $stmt->bind_param('issssdiiiisi', $cat, $name, $sku, $imagePath, $description, $price, $stock, $isActive, $isFeatured, $isNew, $displaySection, $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Product updated.' : 'Could not update product.', $ok ? 'success' : 'error');
                }
            }
        } elseif ($action === 'delete_product') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM products WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Product deleted.' : 'Could not delete product.', $ok ? 'success' : 'error');
                }
            }
        }
        admin_redirect('controls.php', ['section' => 'products']);
    }

    if ($section === 'users') {
        if ($action === 'create_user') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'active');
            if ($fullName !== '' && $email !== '') {
                $stmt = $db->prepare('INSERT INTO users (full_name, email, phone, status) VALUES (?, ?, ?, ?)');
                if ($stmt) {
                    $stmt->bind_param('ssss', $fullName, $email, $phone, $status);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'User added.' : 'Could not add user (email must be unique).', $ok ? 'success' : 'error');
                }
            } else {
                admin_flash_set('Name and email are required.', 'error');
            }
        } elseif ($action === 'delete_user') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'User deleted.' : 'Could not delete user.', $ok ? 'success' : 'error');
                }
            }
        }
        admin_redirect('controls.php', ['section' => 'users']);
    }

    if ($section === 'orders') {
        if ($action === 'create_order') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $customerName = trim((string) ($_POST['customer_name'] ?? ''));
            $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
            $totalAmount = (float) ($_POST['total_amount'] ?? 0);
            $status = (string) ($_POST['status'] ?? 'pending');
            if ($customerName !== '' && $customerEmail !== '') {
                $orderNumber = 'ORD-' . strtoupper(substr(md5((string) microtime(true)), 0, 8));
                $uid = $userId > 0 ? $userId : null;
                $stmt = $db->prepare(
                    'INSERT INTO orders (order_number, user_id, customer_name, customer_email, total_amount, status)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                if ($stmt) {
                    $stmt->bind_param('sissds', $orderNumber, $uid, $customerName, $customerEmail, $totalAmount, $status);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Order created.' : 'Could not create order.', $ok ? 'success' : 'error');
                }
            } else {
                admin_flash_set('Customer name and email are required.', 'error');
            }
        } elseif ($action === 'update_order_status') {
            $id = (int) ($_POST['id'] ?? 0);
            $status = (string) ($_POST['status'] ?? 'pending');
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('si', $status, $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Order status updated.' : 'Could not update status.', $ok ? 'success' : 'error');
                }
            }
        } elseif ($action === 'delete_order') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM orders WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Order deleted.' : 'Could not delete order.', $ok ? 'success' : 'error');
                }
            }
        }
        admin_redirect('controls.php', ['section' => 'orders']);
    }

    if ($section === 'stock') {
        if ($action === 'update_stock') {
            $id = (int) ($_POST['id'] ?? 0);
            $stock = (int) ($_POST['stock_qty'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('ii', $stock, $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Stock updated.' : 'Could not update stock.', $ok ? 'success' : 'error');
                }
            }
        }
        admin_redirect('controls.php', ['section' => 'stock']);
    }

    if ($section === 'coupons') {
        if ($action === 'create_coupon') {
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $type = (string) ($_POST['discount_type'] ?? 'percent');
            $value = (float) ($_POST['discount_value'] ?? 0);
            $startDate = trim((string) ($_POST['start_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $usageLimit = trim((string) ($_POST['usage_limit'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($code !== '') {
                $usage = $usageLimit === '' ? null : (int) $usageLimit;
                $start = $startDate === '' ? null : $startDate;
                $end = $endDate === '' ? null : $endDate;
                $stmt = $db->prepare(
                    'INSERT INTO coupons (code, discount_type, discount_value, start_date, end_date, usage_limit, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                if ($stmt) {
                    $stmt->bind_param('ssdssii', $code, $type, $value, $start, $end, $usage, $isActive);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Coupon added.' : 'Could not add coupon (code must be unique).', $ok ? 'success' : 'error');
                }
            }
        } elseif ($action === 'delete_coupon') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM coupons WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Coupon deleted.' : 'Could not delete coupon.', $ok ? 'success' : 'error');
                }
            }
        }
        admin_redirect('controls.php', ['section' => 'coupons']);
    }

    if ($section === 'hero') {
        if ($action === 'create_hero_slide') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $subtitle = trim((string) ($_POST['subtitle'] ?? ''));
            $buttonText = trim((string) ($_POST['button_text'] ?? 'Shop Now'));
            $buttonLink = trim((string) ($_POST['button_link'] ?? '#featured-products'));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $imagePath = handle_hero_image_upload('image_file', '');

            if ($title === '') {
                admin_flash_set('Slide title is required.', 'error');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO hero_sections (title, subtitle, button_text, button_link, image, sort_order, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                if ($stmt) {
                    $stmt->bind_param('sssssii', $title, $subtitle, $buttonText, $buttonLink, $imagePath, $sortOrder, $isActive);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Hero slide added.' : 'Could not add hero slide.', $ok ? 'success' : 'error');
                }
            }
        } elseif ($action === 'create_hero_slide_from_product') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $subtitle = trim((string) ($_POST['subtitle'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($productId <= 0) {
                admin_flash_set('Please select a product to create hero slide.', 'error');
            } else {
                $productStmt = $db->prepare(
                    'SELECT id, name, image_path
                     FROM products
                     WHERE id = ? AND is_active = 1
                     LIMIT 1'
                );

                if ($productStmt) {
                    $productStmt->bind_param('i', $productId);
                    $productStmt->execute();
                    $productResult = $productStmt->get_result();
                    $productRow = $productResult ? $productResult->fetch_assoc() : null;
                    $productStmt->close();

                    if (!is_array($productRow)) {
                        admin_flash_set('Selected product is not available or not active.', 'error');
                    } else {
                        $productName = trim((string) ($productRow['name'] ?? ''));
                        $imagePath = trim((string) ($productRow['image_path'] ?? ''));

                        if ($title === '') {
                            $title = $productName;
                        }

                        if ($title === '') {
                            admin_flash_set('Slide title is required.', 'error');
                        } else {
                            $buttonText = 'Shop Now';
                            $buttonLink = 'shop-item.php?id=' . $productId;

                            $insertStmt = $db->prepare(
                                'INSERT INTO hero_sections (title, subtitle, button_text, button_link, image, sort_order, is_active)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)'
                            );
                            if ($insertStmt) {
                                $insertStmt->bind_param('sssssii', $title, $subtitle, $buttonText, $buttonLink, $imagePath, $sortOrder, $isActive);
                                $ok = $insertStmt->execute();
                                $insertStmt->close();
                                admin_flash_set($ok ? 'Hero slide added from product.' : 'Could not add hero slide from product.', $ok ? 'success' : 'error');
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'update_hero_slide') {
            $id = (int) ($_POST['id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $subtitle = trim((string) ($_POST['subtitle'] ?? ''));
            $buttonText = trim((string) ($_POST['button_text'] ?? 'Shop Now'));
            $buttonLink = trim((string) ($_POST['button_link'] ?? '#featured-products'));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $currentImage = trim((string) ($_POST['current_image'] ?? ''));
            $imagePath = handle_hero_image_upload('image_file', $currentImage);

            if ($id > 0 && $title !== '') {
                $stmt = $db->prepare(
                    'UPDATE hero_sections
                     SET title = ?, subtitle = ?, button_text = ?, button_link = ?, image = ?, sort_order = ?, is_active = ?
                     WHERE id = ?'
                );
                if ($stmt) {
                    $stmt->bind_param('sssssiii', $title, $subtitle, $buttonText, $buttonLink, $imagePath, $sortOrder, $isActive, $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Hero slide updated.' : 'Could not update hero slide.', $ok ? 'success' : 'error');
                }
            }
        } elseif ($action === 'delete_hero_slide') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM hero_sections WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Hero slide deleted.' : 'Could not delete hero slide.', $ok ? 'success' : 'error');
                }
            }
        }
        admin_redirect('controls.php', ['section' => 'hero']);
    }

    if ($section === 'homepage_sections') {
        if ($action === 'create_homepage_section') {
            $sectionName = trim((string) ($_POST['section_name'] ?? ''));
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            $displayOrder = (int) ($_POST['display_order'] ?? 0);
            if ($sectionName === '') {
                admin_flash_set('Section name is required.', 'error');
            } else {
                $stmt = $db->prepare('INSERT INTO homepage_sections (section_name, is_enabled, display_order) VALUES (?, ?, ?)');
                if ($stmt) {
                    $stmt->bind_param('sii', $sectionName, $isEnabled, $displayOrder);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Homepage section added.' : 'Could not add section (name must be unique).', $ok ? 'success' : 'error');
                }
            }
        } elseif ($action === 'update_homepage_section') {
            $id = (int) ($_POST['id'] ?? 0);
            $sectionName = trim((string) ($_POST['section_name'] ?? ''));
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            $displayOrder = (int) ($_POST['display_order'] ?? 0);
            if ($id > 0 && $sectionName !== '') {
                $stmt = $db->prepare('UPDATE homepage_sections SET section_name = ?, is_enabled = ?, display_order = ? WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('siii', $sectionName, $isEnabled, $displayOrder, $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Homepage section updated.' : 'Could not update section.', $ok ? 'success' : 'error');
                }
            }
        } elseif ($action === 'delete_homepage_section') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM homepage_sections WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Homepage section deleted.' : 'Could not delete section.', $ok ? 'success' : 'error');
                }
            }
        }
        admin_redirect('controls.php', ['section' => 'homepage_sections']);
    }

    if ($section === 'content_labels') {
        if ($action === 'save_content_labels_simple') {
            $simpleKeys = [
                'home_products_heading',
                'home_products_limit',
                'home_products_columns',
                'new_arrivals_heading',
                'new_arrivals_limit',
                'new_arrivals_columns',
                'featured_heading',
                'featured_limit',
                'featured_columns',
                'nav_pages_label',
                'sidebar_all_categories_label',
                'sidebar_filter_title',
            ];
            $stmt = $db->prepare('INSERT INTO site_content (content_key, content_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE content_value = VALUES(content_value)');
            if ($stmt) {
                foreach ($simpleKeys as $key) {
                    $value = trim((string) ($_POST[$key] ?? ''));
                    $stmt->bind_param('ss', $key, $value);
                    $stmt->execute();
                }
                $stmt->close();
                admin_flash_set('Homepage settings saved.', 'success');
            } else {
                admin_flash_set('Could not save homepage settings.', 'error');
            }
        } elseif ($action === 'create_content_label') {
            $key = trim((string) ($_POST['content_key'] ?? ''));
            $value = trim((string) ($_POST['content_value'] ?? ''));
            if ($key === '') {
                admin_flash_set('Content key is required.', 'error');
            } else {
                $stmt = $db->prepare('INSERT INTO site_content (content_key, content_value) VALUES (?, ?)');
                if ($stmt) {
                    $stmt->bind_param('ss', $key, $value);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Label added.' : 'Could not add label (key may already exist).', $ok ? 'success' : 'error');
                }
            }
        } elseif ($action === 'update_content_label') {
            $key = trim((string) ($_POST['content_key'] ?? ''));
            $value = trim((string) ($_POST['content_value'] ?? ''));
            if ($key !== '') {
                $stmt = $db->prepare('INSERT INTO site_content (content_key, content_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE content_value = VALUES(content_value)');
                if ($stmt) {
                    $stmt->bind_param('ss', $key, $value);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Label updated.' : 'Could not update label.', $ok ? 'success' : 'error');
                }
            }
        } elseif ($action === 'delete_content_label') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM site_content WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    admin_flash_set($ok ? 'Label deleted.' : 'Could not delete label.', $ok ? 'success' : 'error');
                }
            }
        }
        admin_redirect('controls.php', ['section' => 'content_labels']);
    }
}

$flash = admin_flash_get();
$categoriesData = [];
$categoriesResult = $db->query(
    'SELECT c.id, c.name, c.slug, c.parent_id, c.is_active, c.created_at, p.name AS parent_name
     FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     ORDER BY COALESCE(c.parent_id, 0) ASC, c.name ASC'
);
if ($categoriesResult instanceof mysqli_result) {
    while ($row = $categoriesResult->fetch_assoc()) {
        $categoriesData[] = $row;
    }
    $categoriesResult->free();
}
$categoryOptionRows = build_category_option_rows($categoriesData, true);

$products = $db->query(
    'SELECT p.id, p.name, p.sku, p.image_path, p.price, p.stock_qty, p.is_active, p.is_featured, p.is_new, p.display_section, p.description, p.created_at, p.category_id, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     ORDER BY p.id DESC'
);
$users = $db->query('SELECT id, full_name, email, phone, status, created_at FROM users ORDER BY id DESC');
$orders = $db->query('SELECT id, order_number, user_id, customer_name, customer_email, total_amount, status, created_at FROM orders ORDER BY id DESC');
$coupons = $db->query('SELECT id, code, discount_type, discount_value, start_date, end_date, usage_limit, is_active, created_at FROM coupons ORDER BY id DESC');
$heroSlides = $db->query('SELECT id, title, subtitle, button_text, button_link, image, sort_order, is_active, created_at FROM hero_sections ORDER BY sort_order ASC, id DESC');
$heroSourceProducts = $db->query(
    "SELECT id, name, sku, image_path
     FROM products
     WHERE is_active = 1
       AND (display_section IS NULL OR display_section <> 'none')
     ORDER BY name ASC, id DESC"
);
$homepageSections = $db->query('SELECT id, section_name, is_enabled, display_order, created_at FROM homepage_sections ORDER BY display_order ASC, id ASC');
$contentLabels = $db->query('SELECT id, content_key, content_value, updated_at FROM site_content ORDER BY content_key ASC');
$contentLabelMap = [];
$contentLabelMapResult = $db->query('SELECT content_key, content_value FROM site_content');
if ($contentLabelMapResult instanceof mysqli_result) {
    while ($contentRow = $contentLabelMapResult->fetch_assoc()) {
        $mapKey = trim((string) ($contentRow['content_key'] ?? ''));
        if ($mapKey === '') {
            continue;
        }
        $contentLabelMap[$mapKey] = trim((string) ($contentRow['content_value'] ?? ''));
    }
    $contentLabelMapResult->free();
}
$homeSectionLabel = $contentLabelMap['home_products_heading'] ?? 'Home';
$newArrivalsSectionLabel = $contentLabelMap['new_arrivals_heading'] ?? 'New Arrivals';
$featuredSectionLabel = $contentLabelMap['featured_heading'] ?? 'Featured';
if ($homeSectionLabel === '') {
    $homeSectionLabel = 'Home';
}
if ($newArrivalsSectionLabel === '') {
    $newArrivalsSectionLabel = 'New Arrivals';
}
if ($featuredSectionLabel === '') {
    $featuredSectionLabel = 'Featured';
}
$homeProductsLimitLabel = $contentLabelMap['home_products_limit'] ?? '12';
$homeProductsColumnsLabel = $contentLabelMap['home_products_columns'] ?? '3';
$newArrivalsLimitLabel = $contentLabelMap['new_arrivals_limit'] ?? '8';
$newArrivalsColumnsLabel = $contentLabelMap['new_arrivals_columns'] ?? '4';
$featuredLimitLabel = $contentLabelMap['featured_limit'] ?? '8';
$featuredColumnsLabel = $contentLabelMap['featured_columns'] ?? '4';
$navPagesLabel = $contentLabelMap['nav_pages_label'] ?? 'Pages';
$sidebarAllCategoriesLabel = $contentLabelMap['sidebar_all_categories_label'] ?? 'All Categories';
$sidebarFilterTitleLabel = $contentLabelMap['sidebar_filter_title'] ?? 'Filter';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Controls</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f4f6f9; margin: 0; }
    .topbar { background: #1f2d3d; color: #fff; padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; }
    .topbar a { color: #fff; text-decoration: none; margin-left: 8px; background: #c0392b; padding: 8px 12px; border-radius: 4px; display: inline-block; }
    .topbar .alt { background: #34495e; }
    .wrap { padding: 18px; }
    .tabs a { text-decoration: none; color: #333; background: #e9edf1; padding: 8px 12px; margin-right: 6px; border-radius: 4px; display: inline-block; }
    .tabs a.active { background: #1f2d3d; color: #fff; }
    .panel { margin-top: 14px; background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 14px; }
    .flash { margin-top: 10px; padding: 10px; border-radius: 4px; border: 1px solid #ddd; }
    .flash.success { background: #e8f8ef; color: #0b6b3a; border-color: #bce6cd; }
    .flash.error { background: #ffecec; color: #8a1f1f; border-color: #f5bdbd; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
    th { background: #f0f2f5; }
    input, select, textarea { width: 100%; padding: 6px; box-sizing: border-box; border: 1px solid #bbb; border-radius: 4px; }
    textarea { min-height: 64px; }
    form.inline { display: inline; }
    .btn { border: 0; border-radius: 4px; padding: 7px 10px; cursor: pointer; }
    .btn-primary { background: #1f78d1; color: #fff; }
    .btn-danger { background: #c0392b; color: #fff; }
    .btn-muted { background: #666; color: #fff; }
    .grid-4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
    .grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
    .grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    .mt { margin-top: 10px; }
    details.advanced summary { cursor: pointer; font-weight: 600; }
    @media (max-width: 900px) { .grid-4, .grid-3, .grid-2 { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="topbar">
    <div>Admin Controls</div>
    <div>
      <a class="alt" href="dashboard.php">Dashboard</a>
      <a href="logout.php">Logout</a>
    </div>
  </div>
  <div class="wrap">
    <div class="tabs">
      <a class="<?php echo $section === 'products' ? 'active' : ''; ?>" href="controls.php?section=products">Products</a>
      <a class="<?php echo $section === 'categories' ? 'active' : ''; ?>" href="controls.php?section=categories">Categories</a>
      <a class="<?php echo $section === 'users' ? 'active' : ''; ?>" href="controls.php?section=users">Users</a>
      <a class="<?php echo $section === 'orders' ? 'active' : ''; ?>" href="controls.php?section=orders">Orders</a>
      <a class="<?php echo $section === 'stock' ? 'active' : ''; ?>" href="controls.php?section=stock">Stock</a>
      <a class="<?php echo $section === 'coupons' ? 'active' : ''; ?>" href="controls.php?section=coupons">Offers/Coupons</a>
      <a class="<?php echo $section === 'hero' ? 'active' : ''; ?>" href="controls.php?section=hero">Hero Management</a>
      <a class="<?php echo $section === 'homepage_sections' ? 'active' : ''; ?>" href="controls.php?section=homepage_sections">Homepage Sections</a>
      <a class="<?php echo $section === 'content_labels' ? 'active' : ''; ?>" href="controls.php?section=content_labels">Content Labels</a>
    </div>

    <?php if ($flash): ?>
      <div class="flash <?php echo admin_h((string) ($flash['type'] ?? 'success')); ?>">
        <?php echo admin_h((string) ($flash['message'] ?? '')); ?>
      </div>
    <?php endif; ?>

    <?php if ($section === 'categories'): ?>
      <div class="panel">
        <h3>Manage Categories</h3>
        <form method="post" action="controls.php?section=categories">
          <input type="hidden" name="action" value="create_category">
          <div class="grid-4">
            <div><input type="text" name="name" placeholder="Category name" required></div>
            <div>
              <select name="parent_id">
                <option value="0">Top-level category</option>
                <?php foreach ($categoryOptionRows as $categoryOption): ?>
                  <option value="<?php echo (int) $categoryOption['id']; ?>"><?php echo admin_h((string) $categoryOption['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div><label><input type="checkbox" name="is_active" checked> Active</label></div>
            <div><button class="btn btn-primary" type="submit">Add Category</button></div>
          </div>
        </form>
        <form id="bulkDeleteCategoriesForm" method="post" action="controls.php?section=categories" class="mt">
          <input type="hidden" name="action" value="bulk_delete_categories">
          <div class="mt">
            <button class="btn btn-danger" type="submit">Delete Selected</button>
          </div>
        </form>
        <table>
          <thead><tr><th>Select</th><th>ID</th><th>Name</th><th>Slug</th><th>Parent</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
          <tbody>
            <?php if ($categoriesData === []): ?>
              <tr><td colspan="8">No categories found.</td></tr>
            <?php else: foreach ($categoriesData as $row): ?>
              <tr>
                <td>
                  <input type="checkbox" class="js-category-select" value="<?php echo (int) $row['id']; ?>">
                </td>
                <td><?php echo (int) $row['id']; ?></td>
                <td colspan="5">
                  <form method="post" action="controls.php?section=categories">
                    <input type="hidden" name="action" value="update_category">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <div class="grid-4">
                      <div><input type="text" name="name" value="<?php echo admin_h((string) $row['name']); ?>" required></div>
                      <div><input type="text" value="<?php echo admin_h((string) $row['slug']); ?>" disabled></div>
                      <div>
                        <select name="parent_id">
                          <option value="0">Top-level category</option>
                          <?php foreach ($categoryOptionRows as $categoryOption): ?>
                            <?php $optionId = (int) $categoryOption['id']; ?>
                            <?php if ($optionId === (int) $row['id']) { continue; } ?>
                            <option value="<?php echo $optionId; ?>" <?php echo (int) ($row['parent_id'] ?? 0) === $optionId ? 'selected' : ''; ?>>
                              <?php echo admin_h((string) $categoryOption['label']); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div><label><input type="checkbox" name="is_active" <?php echo (int) $row['is_active'] === 1 ? 'checked' : ''; ?>> Active</label></div>
                    </div>
                    <div class="mt"><small>Parent: <?php echo admin_h((string) ($row['parent_name'] ?? 'Top-level')); ?> | Created: <?php echo admin_h((string) $row['created_at']); ?></small></div>
                    <div class="mt"><button class="btn btn-muted" type="submit">Update</button></div>
                  </form>
                </td>
                <td>
                  <form class="inline" method="post" action="controls.php?section=categories">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <script>
        (function () {
          var form = document.getElementById('bulkDeleteCategoriesForm');
          if (!form) {
            return;
          }

          form.addEventListener('submit', function (event) {
            var checked = document.querySelectorAll('.js-category-select:checked');
            if (!checked.length) {
              event.preventDefault();
              alert('Please select at least one category.');
              return;
            }

            var ok = confirm('Delete ' + checked.length + ' selected categories?');
            if (!ok) {
              event.preventDefault();
              return;
            }

            form.querySelectorAll('input[name="category_ids[]"]').forEach(function (node) {
              node.remove();
            });

            checked.forEach(function (box) {
              var hidden = document.createElement('input');
              hidden.type = 'hidden';
              hidden.name = 'category_ids[]';
              hidden.value = box.value;
              form.appendChild(hidden);
            });
          });
        })();
      </script>
    <?php endif; ?>

    <?php if ($section === 'products'): ?>
      <div class="panel">
        <h3>Add/Edit/Delete Products</h3>
        <form method="post" action="controls.php?section=products" enctype="multipart/form-data">
          <input type="hidden" name="action" value="create_product">
          <div class="grid-4">
            <div><input type="text" name="name" placeholder="Product name" required></div>
            <div><input type="text" name="sku" placeholder="SKU" required></div>
            <div>
              <select name="category_id">
                <option value="0">No category</option>
                <?php foreach ($categoryOptionRows as $categoryOption): ?>
                  <option value="<?php echo (int) $categoryOption['id']; ?>"><?php echo admin_h((string) $categoryOption['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div><input type="number" step="0.01" name="price" placeholder="Price" required></div>
            <div><input type="number" name="stock_qty" placeholder="Stock" value="0" required></div>
            <div><input type="file" name="image_file" accept=".jpg,.jpeg,.png,.gif,.webp"></div>
            <div><label><input type="checkbox" name="is_active" checked aria-label="Active"></label></div>
            <div>
              <select name="display_section">
                <option value="home"><?php echo admin_h($homeSectionLabel); ?></option>
                <option value="new_arrivals"><?php echo admin_h($newArrivalsSectionLabel); ?></option>
                <option value="featured"><?php echo admin_h($featuredSectionLabel); ?></option>
                <option value="none">None</option>
              </select>
            </div>
          </div>
          <div class="mt"><textarea name="description" placeholder="Description"></textarea></div>
          <div class="mt"><button class="btn btn-primary" type="submit">Add Product</button></div>
        </form>
        <table>
          <thead><tr><th>ID</th><th>Name</th><th>SKU</th><th>Category</th><th>Price</th><th>Stock</th><th>Flags</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (!$products || $products->num_rows === 0): ?>
              <tr><td colspan="9">No products found.</td></tr>
            <?php else: while ($row = $products->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td colspan="7">
                  <form method="post" action="controls.php?section=products" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_product">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <input type="hidden" name="current_image_path" value="<?php echo admin_h((string) $row['image_path']); ?>">
                    <div class="grid-4">
                      <div><input type="text" name="name" value="<?php echo admin_h((string) $row['name']); ?>" required></div>
                      <div><input type="text" name="sku" value="<?php echo admin_h((string) $row['sku']); ?>" required></div>
                      <div>
                        <select name="category_id">
                          <option value="0">No category</option>
                          <?php foreach ($categoryOptionRows as $categoryOption): ?>
                            <option value="<?php echo (int) $categoryOption['id']; ?>" <?php echo (int) $row['category_id'] === (int) $categoryOption['id'] ? 'selected' : ''; ?>>
                              <?php echo admin_h((string) $categoryOption['label']); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div><input type="number" step="0.01" name="price" value="<?php echo admin_h((string) $row['price']); ?>" required></div>
                      <div><input type="number" name="stock_qty" value="<?php echo (int) $row['stock_qty']; ?>" required></div>
                      <div><input type="file" name="image_file" accept=".jpg,.jpeg,.png,.gif,.webp"></div>
                      <div><label><input type="checkbox" name="is_active" <?php echo (int) $row['is_active'] === 1 ? 'checked' : ''; ?> aria-label="Active"></label></div>
                      <div>
                        <select name="display_section">
                          <?php
                          $displaySectionValue = normalize_display_section((string) ($row['display_section'] ?? 'home'));
                          if ($displaySectionValue === 'home' || $displaySectionValue === 'none') {
                              if ((int) ($row['is_new'] ?? 0) === 1) {
                                  $displaySectionValue = 'new_arrivals';
                              } elseif ((int) ($row['is_featured'] ?? 0) === 1) {
                                  $displaySectionValue = 'featured';
                              }
                          }
                          ?>
                          <option value="home" <?php echo $displaySectionValue === 'home' ? 'selected' : ''; ?>><?php echo admin_h($homeSectionLabel); ?></option>
                          <option value="new_arrivals" <?php echo $displaySectionValue === 'new_arrivals' ? 'selected' : ''; ?>><?php echo admin_h($newArrivalsSectionLabel); ?></option>
                          <option value="featured" <?php echo $displaySectionValue === 'featured' ? 'selected' : ''; ?>><?php echo admin_h($featuredSectionLabel); ?></option>
                          <option value="none" <?php echo $displaySectionValue === 'none' ? 'selected' : ''; ?>>None</option>
                        </select>
                      </div>
                    </div>
                    <?php if ((string) $row['image_path'] !== ''): ?>
                      <div class="mt">
                        <img src="../<?php echo admin_h((string) $row['image_path']); ?>" alt="product" style="max-height:80px;border:1px solid #ddd;padding:2px;">
                      </div>
                    <?php endif; ?>
                    <div class="mt"><textarea name="description"><?php echo admin_h((string) $row['description']); ?></textarea></div>
                    <div class="mt"><button class="btn btn-muted" type="submit">Update</button></div>
                  </form>
                </td>
                <td>
                  <form class="inline" method="post" action="controls.php?section=products">
                    <input type="hidden" name="action" value="delete_product">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($section === 'users'): ?>
      <div class="panel">
        <h3>View All Users</h3>
        <form method="post" action="controls.php?section=users">
          <input type="hidden" name="action" value="create_user">
          <div class="grid-4">
            <div><input type="text" name="full_name" placeholder="Full name" required></div>
            <div><input type="email" name="email" placeholder="Email" required></div>
            <div><input type="text" name="phone" placeholder="Phone"></div>
            <div>
              <select name="status">
                <option value="active">Active</option>
                <option value="blocked">Blocked</option>
              </select>
            </div>
          </div>
          <div class="mt"><button class="btn btn-primary" type="submit">Add User</button></div>
        </form>
        <table>
          <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Status</th><th>Joined</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (!$users || $users->num_rows === 0): ?>
              <tr><td colspan="7">No users found.</td></tr>
            <?php else: while ($row = $users->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td><?php echo admin_h((string) $row['full_name']); ?></td>
                <td><?php echo admin_h((string) $row['email']); ?></td>
                <td><?php echo admin_h((string) $row['phone']); ?></td>
                <td><?php echo admin_h((string) $row['status']); ?></td>
                <td><?php echo admin_h((string) $row['created_at']); ?></td>
                <td>
                  <form class="inline" method="post" action="controls.php?section=users">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($section === 'orders'): ?>
      <div class="panel">
        <h3>View/Manage Orders + Update Status</h3>
        <form method="post" action="controls.php?section=orders">
          <input type="hidden" name="action" value="create_order">
          <div class="grid-4">
            <div><input type="text" name="customer_name" placeholder="Customer name" required></div>
            <div><input type="email" name="customer_email" placeholder="Customer email" required></div>
            <div><input type="number" step="0.01" name="total_amount" placeholder="Total amount" required></div>
            <div>
              <select name="status">
                <option value="pending">pending</option>
                <option value="processing">processing</option>
                <option value="shipped">shipped</option>
                <option value="delivered">delivered</option>
                <option value="cancelled">cancelled</option>
              </select>
            </div>
          </div>
          <div class="mt"><button class="btn btn-primary" type="submit">Create Order</button></div>
        </form>
        <table>
          <thead><tr><th>Order #</th><th>Customer</th><th>Email</th><th>Total</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
          <tbody>
            <?php if (!$orders || $orders->num_rows === 0): ?>
              <tr><td colspan="7">No orders found.</td></tr>
            <?php else: while ($row = $orders->fetch_assoc()): ?>
              <tr>
                <td><?php echo admin_h((string) $row['order_number']); ?></td>
                <td><?php echo admin_h((string) $row['customer_name']); ?></td>
                <td><?php echo admin_h((string) $row['customer_email']); ?></td>
                <td><?php echo number_format((float) $row['total_amount'], 2); ?></td>
                <td>
                  <form class="inline" method="post" action="controls.php?section=orders">
                    <input type="hidden" name="action" value="update_order_status">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <select name="status">
                      <?php foreach (['pending','processing','shipped','delivered','cancelled'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $row['status'] === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-muted mt" type="submit">Update</button>
                  </form>
                </td>
                <td><?php echo admin_h((string) $row['created_at']); ?></td>
                <td>
                  <form class="inline" method="post" action="controls.php?section=orders">
                    <input type="hidden" name="action" value="delete_order">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($section === 'stock'): ?>
      <div class="panel">
        <h3>Manage Stock</h3>
        <table>
          <thead><tr><th>ID</th><th>Product</th><th>SKU</th><th>Current Stock</th><th>Update</th></tr></thead>
          <tbody>
            <?php if (!$products || $products->num_rows === 0): ?>
              <tr><td colspan="5">No products found.</td></tr>
            <?php else: $products->data_seek(0); while ($row = $products->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td><?php echo admin_h((string) $row['name']); ?></td>
                <td><?php echo admin_h((string) $row['sku']); ?></td>
                <td><?php echo (int) $row['stock_qty']; ?></td>
                <td>
                  <form class="inline" method="post" action="controls.php?section=stock">
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <input type="number" name="stock_qty" value="<?php echo (int) $row['stock_qty']; ?>" style="width:110px;">
                    <button class="btn btn-primary" type="submit">Save</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($section === 'coupons'): ?>
      <div class="panel">
        <h3>Create Offers/Coupons</h3>
        <form method="post" action="controls.php?section=coupons">
          <input type="hidden" name="action" value="create_coupon">
          <div class="grid-4">
            <div><input type="text" name="code" placeholder="Coupon code" required></div>
            <div>
              <select name="discount_type">
                <option value="percent">percent</option>
                <option value="fixed">fixed</option>
              </select>
            </div>
            <div><input type="number" step="0.01" name="discount_value" placeholder="Discount value" required></div>
            <div><input type="number" name="usage_limit" placeholder="Usage limit"></div>
            <div><input type="date" name="start_date"></div>
            <div><input type="date" name="end_date"></div>
            <div><label><input type="checkbox" name="is_active" checked> Active</label></div>
          </div>
          <div class="mt"><button class="btn btn-primary" type="submit">Add Coupon</button></div>
        </form>
        <table>
          <thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Start</th><th>End</th><th>Usage Limit</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (!$coupons || $coupons->num_rows === 0): ?>
              <tr><td colspan="8">No coupons found.</td></tr>
            <?php else: while ($row = $coupons->fetch_assoc()): ?>
              <tr>
                <td><?php echo admin_h((string) $row['code']); ?></td>
                <td><?php echo admin_h((string) $row['discount_type']); ?></td>
                <td><?php echo number_format((float) $row['discount_value'], 2); ?></td>
                <td><?php echo admin_h((string) $row['start_date']); ?></td>
                <td><?php echo admin_h((string) $row['end_date']); ?></td>
                <td><?php echo admin_h((string) $row['usage_limit']); ?></td>
                <td><?php echo (int) $row['is_active'] === 1 ? 'Active' : 'Inactive'; ?></td>
                <td>
                  <form class="inline" method="post" action="controls.php?section=coupons">
                    <input type="hidden" name="action" value="delete_coupon">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($section === 'hero'): ?>
      <div class="panel">
        <h3>Homepage Hero Slider</h3>
        <form method="post" action="controls.php?section=hero" enctype="multipart/form-data">
          <input type="hidden" name="action" value="create_hero_slide">
          <div class="grid-4">
            <div><input type="text" name="title" placeholder="Slide title" required></div>
            <div><input type="text" name="subtitle" placeholder="Slide subtitle"></div>
            <div><input type="text" name="button_text" placeholder="Button text (e.g. Shop Now)" value="Shop Now"></div>
            <div><input type="text" name="button_link" placeholder="Button link" value="#featured-products"></div>
            <div><input type="number" name="sort_order" placeholder="Sort order" value="0"></div>
            <div><input type="file" name="image_file" accept=".jpg,.jpeg,.png,.gif,.webp"></div>
            <div><label><input type="checkbox" name="is_active" checked> Active</label></div>
          </div>
          <div class="mt"><button class="btn btn-primary" type="submit">Add Hero Slide</button></div>
        </form>
        <div class="mt"></div>
        <form method="post" action="controls.php?section=hero">
          <input type="hidden" name="action" value="create_hero_slide_from_product">
          <div class="grid-4">
            <div>
              <select name="product_id" required>
                <option value="">Select displayed product</option>
                <?php if ($heroSourceProducts instanceof mysqli_result): ?>
                  <?php while ($heroProduct = $heroSourceProducts->fetch_assoc()): ?>
                    <?php
                    $heroProductId = (int) ($heroProduct['id'] ?? 0);
                    $heroProductName = trim((string) ($heroProduct['name'] ?? 'Product'));
                    $heroProductSku = trim((string) ($heroProduct['sku'] ?? ''));
                    ?>
                    <option value="<?php echo $heroProductId; ?>">
                      <?php echo admin_h($heroProductName . ($heroProductSku !== '' ? ' (' . $heroProductSku . ')' : '')); ?>
                    </option>
                  <?php endwhile; ?>
                  <?php $heroSourceProducts->free(); ?>
                <?php endif; ?>
              </select>
            </div>
            <div><input type="text" name="title" placeholder="Hero title (optional, defaults to product name)"></div>
            <div><input type="text" name="subtitle" placeholder="Hero subtitle"></div>
            <div><input type="number" name="sort_order" placeholder="Sort order" value="0"></div>
            <div><label><input type="checkbox" name="is_active" checked> Active</label></div>
          </div>
          <div class="mt"><small>Uses selected product image and auto-links button to that product page.</small></div>
          <div class="mt"><button class="btn btn-primary" type="submit">Add From Product</button></div>
        </form>

        <table>
          <thead><tr><th>ID</th><th>Slide Content</th><th>Preview</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (!$heroSlides || $heroSlides->num_rows === 0): ?>
              <tr><td colspan="4">No hero slides found. Add your first slide.</td></tr>
            <?php else: while ($row = $heroSlides->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td>
                  <form method="post" action="controls.php?section=hero" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_hero_slide">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <input type="hidden" name="current_image" value="<?php echo admin_h((string) $row['image']); ?>">
                    <div class="grid-4">
                      <div><input type="text" name="title" value="<?php echo admin_h((string) $row['title']); ?>" required></div>
                      <div><input type="text" name="subtitle" value="<?php echo admin_h((string) $row['subtitle']); ?>"></div>
                      <div><input type="text" name="button_text" value="<?php echo admin_h((string) $row['button_text']); ?>"></div>
                      <div><input type="text" name="button_link" value="<?php echo admin_h((string) $row['button_link']); ?>"></div>
                      <div><input type="number" name="sort_order" value="<?php echo (int) $row['sort_order']; ?>"></div>
                      <div><input type="file" name="image_file" accept=".jpg,.jpeg,.png,.gif,.webp"></div>
                      <div><label><input type="checkbox" name="is_active" <?php echo (int) $row['is_active'] === 1 ? 'checked' : ''; ?>> Active</label></div>
                    </div>
                    <div class="mt"><button class="btn btn-muted" type="submit">Update Slide</button></div>
                  </form>
                </td>
                <td style="min-width:170px;">
                  <?php if ((string) $row['image'] !== ''): ?>
                    <img src="../<?php echo admin_h((string) $row['image']); ?>" alt="hero" style="max-height:80px;border:1px solid #ddd;padding:2px;">
                  <?php else: ?>
                    <small>No image set</small>
                  <?php endif; ?>
                  <div class="mt"><small><?php echo (int) $row['is_active'] === 1 ? 'Active' : 'Inactive'; ?></small></div>
                </td>
                <td>
                  <form class="inline" method="post" action="controls.php?section=hero">
                    <input type="hidden" name="action" value="delete_hero_slide">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($section === 'homepage_sections'): ?>
      <div class="panel">
        <h3>Homepage Sections</h3>
        <p>Control which homepage blocks are enabled and in what order they appear.</p>
        <form method="post" action="controls.php?section=homepage_sections">
          <input type="hidden" name="action" value="create_homepage_section">
          <div class="grid-4">
            <div>
              <select name="section_name" required>
                <option value="hero">hero</option>
                <option value="products_from_admin">products_from_admin</option>
                <option value="new_arrivals">new_arrivals</option>
                <option value="featured">featured</option>
                <option value="categories_sidebar">categories_sidebar</option>
              </select>
            </div>
            <div><input type="number" name="display_order" value="0" placeholder="Display order"></div>
            <div><label><input type="checkbox" name="is_enabled" checked> Enabled</label></div>
            <div><button class="btn btn-primary" type="submit">Add Section</button></div>
          </div>
        </form>

        <table>
          <thead><tr><th>ID</th><th>Section</th><th>Enabled</th><th>Display Order</th><th>Created</th><th>Actions</th></tr></thead>
          <tbody>
            <?php if (!$homepageSections || $homepageSections->num_rows === 0): ?>
              <tr><td colspan="6">No homepage sections found.</td></tr>
            <?php else: while ($row = $homepageSections->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td colspan="4">
                  <form method="post" action="controls.php?section=homepage_sections">
                    <input type="hidden" name="action" value="update_homepage_section">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <div class="grid-4">
                      <div>
                        <input type="text" name="section_name" value="<?php echo admin_h((string) $row['section_name']); ?>" required>
                      </div>
                      <div><label><input type="checkbox" name="is_enabled" <?php echo (int) $row['is_enabled'] === 1 ? 'checked' : ''; ?>> Enabled</label></div>
                      <div><input type="number" name="display_order" value="<?php echo (int) $row['display_order']; ?>"></div>
                      <div><small><?php echo admin_h((string) $row['created_at']); ?></small></div>
                    </div>
                    <div class="mt"><button class="btn btn-muted" type="submit">Update</button></div>
                  </form>
                </td>
                <td>
                  <form class="inline" method="post" action="controls.php?section=homepage_sections">
                    <input type="hidden" name="action" value="delete_homepage_section">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <button class="btn btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($section === 'content_labels'): ?>
      <div class="panel">
        <h3>Homepage Settings</h3>
        <p>Edit homepage headings, counts, layout and header labels using simple fields.</p>
        <form method="post" action="controls.php?section=content_labels">
          <input type="hidden" name="action" value="save_content_labels_simple">
          <div class="grid-2">
            <div><label>Home Section Heading</label><input type="text" name="home_products_heading" value="<?php echo admin_h($homeSectionLabel); ?>"></div>
            <div><label>Home Items Count</label><input type="number" min="1" max="48" name="home_products_limit" value="<?php echo admin_h($homeProductsLimitLabel); ?>"></div>
            <div><label>Home Columns (1-4)</label><input type="number" min="1" max="4" name="home_products_columns" value="<?php echo admin_h($homeProductsColumnsLabel); ?>"></div>
            <div><label>New Arrivals Heading</label><input type="text" name="new_arrivals_heading" value="<?php echo admin_h($newArrivalsSectionLabel); ?>"></div>
            <div><label>New Arrivals Items Count</label><input type="number" min="1" max="48" name="new_arrivals_limit" value="<?php echo admin_h($newArrivalsLimitLabel); ?>"></div>
            <div><label>New Arrivals Columns (1-4)</label><input type="number" min="1" max="4" name="new_arrivals_columns" value="<?php echo admin_h($newArrivalsColumnsLabel); ?>"></div>
            <div><label>Featured Heading</label><input type="text" name="featured_heading" value="<?php echo admin_h($featuredSectionLabel); ?>"></div>
            <div><label>Featured Items Count</label><input type="number" min="1" max="48" name="featured_limit" value="<?php echo admin_h($featuredLimitLabel); ?>"></div>
            <div><label>Featured Columns (1-4)</label><input type="number" min="1" max="4" name="featured_columns" value="<?php echo admin_h($featuredColumnsLabel); ?>"></div>
            <div><label>Top Menu Label</label><input type="text" name="nav_pages_label" value="<?php echo admin_h($navPagesLabel); ?>"></div>
            <div><label>Sidebar 'All Categories' Label</label><input type="text" name="sidebar_all_categories_label" value="<?php echo admin_h($sidebarAllCategoriesLabel); ?>"></div>
            <div><label>Sidebar Filter Heading</label><input type="text" name="sidebar_filter_title" value="<?php echo admin_h($sidebarFilterTitleLabel); ?>"></div>
          </div>
          <div class="mt"><button class="btn btn-primary" type="submit">Save Homepage Settings</button></div>
        </form>
        <details class="advanced mt">
          <summary>Advanced: Manage Raw Keys</summary>
          <div class="mt">
            <form method="post" action="controls.php?section=content_labels">
              <input type="hidden" name="action" value="create_content_label">
              <div class="grid-4">
                <div><input type="text" name="content_key" placeholder="content_key (e.g. home_banner_text)" required></div>
                <div><input type="text" name="content_value" placeholder="Label text/value"></div>
                <div><button class="btn btn-primary" type="submit">Add Label</button></div>
              </div>
            </form>
            <table>
              <thead><tr><th>Key</th><th>Label Text</th><th>Last Updated</th><th>Action</th></tr></thead>
              <tbody>
                <?php if (!$contentLabels || $contentLabels->num_rows === 0): ?>
                  <tr><td colspan="4">No content labels found.</td></tr>
                <?php else: while ($row = $contentLabels->fetch_assoc()): ?>
                  <tr>
                    <td><code><?php echo admin_h((string) $row['content_key']); ?></code></td>
                    <td>
                      <form method="post" action="controls.php?section=content_labels">
                        <input type="hidden" name="action" value="update_content_label">
                        <input type="hidden" name="content_key" value="<?php echo admin_h((string) $row['content_key']); ?>">
                        <div class="grid-4">
                          <div><input type="text" name="content_value" value="<?php echo admin_h((string) $row['content_value']); ?>" required></div>
                          <div><button class="btn btn-primary" type="submit">Save</button></div>
                        </div>
                      </form>
                    </td>
                    <td><small><?php echo admin_h((string) $row['updated_at']); ?></small></td>
                    <td>
                      <form class="inline" method="post" action="controls.php?section=content_labels" onsubmit="return confirm('Delete this content label?');">
                        <input type="hidden" name="action" value="delete_content_label">
                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                        <button class="btn btn-danger" type="submit">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endwhile; endif; ?>
              </tbody>
            </table>
          </div>
        </details>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
