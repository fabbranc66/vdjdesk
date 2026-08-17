#pragma once
#ifndef VdjPlugin8H
#define VdjPlugin8H

#define WIN32_LEAN_AND_MEAN
#ifndef NOMINMAX
#define NOMINMAX
#endif
#include <windows.h>

#define VDJ_EXPORT __declspec(dllexport)
#define VDJ_API __stdcall
#define VDJ_BITMAP HBITMAP
#define VDJ_HINSTANCE HINSTANCE
#define VDJ_WINDOW HWND

struct TVdjPluginInfo8
{
    const char *PluginName;
    const char *Author;
    const char *Description;
    const char *Version;
    VDJ_BITMAP Bitmap;
    DWORD Flags;
};

#define VDJFLAG_NODOCK 0x1
#define VDJFLAG_PROCESSAFTERSTOP 0x2
#define VDJFLAG_PROCESSFIRST 0x4
#define VDJFLAG_PROCESSLAST 0x8

#define VDJPARAM_BUTTON 0
#define VDJPARAM_SLIDER 1
#define VDJPARAM_SWITCH 2
#define VDJPARAM_STRING 3
#define VDJPARAM_CUSTOM 4
#define VDJPARAM_RADIO 5
#define VDJPARAM_COMMAND 6

struct TVdjPluginInterface8
{
    DWORD Type;
    const char *Xml;
    void *ImageBuffer;
    int ImageSize;
    VDJ_WINDOW hWnd;
};

#define VDJINTERFACE_DEFAULT 0
#define VDJINTERFACE_SKIN 1
#define VDJINTERFACE_DIALOG 2

struct IVdjCallbacks8
{
    virtual HRESULT SendCommand(const char *command)=0;
    virtual HRESULT GetInfo(const char *command,double *result)=0;
    virtual HRESULT GetStringInfo(const char *command,void *result,int size)=0;
    virtual HRESULT DeclareParameter(void *parameter,int type,int id,const char *name,const char *shortName,float defaultvalue)=0;
    virtual HRESULT GetSongBuffer(int pos, int nb, short **buffer)=0;
};

class IVdjPlugin8
{
public:
    virtual HRESULT VDJ_API OnLoad() {return S_OK;}
    virtual HRESULT VDJ_API OnGetPluginInfo(TVdjPluginInfo8 *info) {return E_NOTIMPL;}
    virtual ULONG VDJ_API Release() {delete this; return S_OK;}
    virtual ~IVdjPlugin8() {}

    HRESULT DeclareParameterButton(int *parameter, int id, const char *name, const char *shortName)
    {return cb->DeclareParameter(parameter,VDJPARAM_BUTTON,id,name,shortName,0.0f);}

    virtual HRESULT VDJ_API OnParameter(int id) {return S_OK;}
    virtual HRESULT VDJ_API OnGetParameterString(int id, char *outParam, int outParamSize) {return E_NOTIMPL;}
    virtual HRESULT VDJ_API OnGetUserInterface(TVdjPluginInterface8 *pluginInterface) {return E_NOTIMPL;}

    HRESULT SendCommand(const char *command) {return cb->SendCommand(command);}
    HRESULT GetInfo(const char *command, double *result) {return cb->GetInfo(command,result);}
    HRESULT GetStringInfo(const char *command, char *result, int size) {return cb->GetStringInfo(command,result,size);}

    VDJ_HINSTANCE hInstance;
    IVdjCallbacks8 *cb;
};

static const GUID CLSID_VdjPlugin8 =
{ 0xED8A8D87, 0xF4F9, 0x4DCD, { 0xBD, 0x24, 0x29, 0x14, 0x12, 0xE9, 0x3B, 0x60 } };

static const GUID IID_IVdjPluginBasic8 =
{ 0xa1d90ea1, 0x4d0d, 0x42dd, { 0xa4, 0xd0, 0xb8, 0xf3, 0x37, 0xb3, 0x21, 0xf1 } };

extern "C" VDJ_EXPORT HRESULT VDJ_API DllGetClassObject(
    const GUID &rclsid,
    const GUID &riid,
    void** ppObject
);

#endif
