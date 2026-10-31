<?php

namespace App\Domain\Website\Content;

class WebsiteStaticAssets
{
    private const DIMENSIONS = ['home_hero' => [1440, 730], 'cars' => [1000, 325], 'scooters' => [1000, 325],
        'island' => [1440, 400], 'robot_phone' => [240, 203], 'robot_standing' => [250, 375], 'robot_wheel' => [300, 450]];

    /** @return list<array{id:string,url:string,width:int,height:int,alt:string}> */
    public function options(): array
    {
        $result = [];
        foreach (self::DIMENSIONS as $id => [$width,$height]) {
            $url = config('website.cms.static_assets.'.$id);
            if (is_string($url) && preg_match('~^/website/images/[a-z0-9-]+\.webp$~D', $url)) {
                $result[] = ['id' => $id, 'url' => $url, 'width' => $width, 'height' => $height, 'alt' => 'Drive Phangan'];
            }
        }

        return $result;
    }

    public function contains(string $id): bool
    {
        return in_array($id, array_column($this->options(), 'id'), true);
    }
}
