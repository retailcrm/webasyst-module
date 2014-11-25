<?php
class retailcrmFeatureModel extends shopFeatureModel
{
    public function getByProduct($product_id)
    {
        $sql = "SELECT DISTINCT `f`.* FROM `{$this->table}` `f`
        JOIN `shop_product_features` `pf` ON `pf`.`feature_id` = `f`.`id`
        WHERE `pf`.`product_id` ".(is_array($product_id) ? 'IN (i:id)' : '= i:id');
        $features = $this->query($sql, array('id' => $product_id))->fetchAll('id');
        $sql = "SELECT DISTINCT `f`.* FROM `{$this->table}` `f`
            JOIN `shop_product_features_selectable` `pf` ON `pf`.`feature_id` = `f`.`id`
            WHERE `pf`.`product_id` " . (is_array($product_id) ? 'IN (i:id)' : '= i:id');
        $features += $this->query($sql, array('id' => $product_id))->fetchAll('id');

        return $features;
    }
}
