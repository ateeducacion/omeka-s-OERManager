<?php

namespace Laminas\Form;

class Form
{
    private array $elements = [];
    private array $data = [];
    public function add(array $element)
    {
        $this->elements[$element['name']] = $element;
        return $this;
    }
    public function getElements()
    {
        return $this->elements;
    }
    public function setData($data)
    {
        $this->data = $data;
        return $this;
    }
    public function getData()
    {
        return $this->data;
    }
    public function isValid()
    {
        return true;
    }
    public function getMessages()
    {
        return [];
    }
}
